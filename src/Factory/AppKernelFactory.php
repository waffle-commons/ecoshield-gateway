<?php

declare(strict_types=1);

namespace App\Factory;

use App\Controller\GatewayController;
use App\Kernel;
use App\Proxy\ProxyController;
use App\Security\AnonymousSecurityContext;
use App\Shield\ResponseCache;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Waffle\Commons\Cache\Factory\CacheFactory;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Config\DotEnv;
use Waffle\Commons\Container\Container;
use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\ErrorHandler\Middleware\ErrorHandlerMiddleware;
use Waffle\Commons\ErrorHandler\Renderer\JsonErrorRenderer;
use Waffle\Commons\Http\Factory\RequestFactory;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\StreamFactory;
use Waffle\Commons\Http\Factory\UriFactory;
use Waffle\Commons\HttpClient\Client;
use Waffle\Commons\Log\StreamLogger;
use Waffle\Commons\Pipeline\CoreRoutingMiddleware;
use Waffle\Commons\Pipeline\Middleware\SecureHeadersMiddleware;
use Waffle\Commons\Pipeline\Middleware\TrustedHostMiddleware;
use Waffle\Commons\Pipeline\MiddlewareStack;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Security\Container\SecureContainer;
use Waffle\Commons\Security\Middleware\SecurityMiddleware;
use Waffle\Commons\Security\Security;

/**
 * Assemblage de la passerelle.
 *
 * Volontairement plus courte que la fabrique du squelette : une passerelle n'a
 * ni base de données, ni sessions, ni CSRF, ni pont d'authentification. Ce
 * qu'elle a — un client HTTP en flux, un cache, un routeur, un pipeline — est
 * ici, et rien d'autre. Chaque service câblé correspond à une phrase du README.
 */
final class AppKernelFactory
{
    /** Taille de corps au-delà de laquelle le Shield laisse filer sans mettre en cache. */
    private const int DEFAULT_MAX_CACHED_BODY = 262_144;

    /** Durée de vie par défaut d'une entrée de cache, en secondes. */
    private const int DEFAULT_CACHE_TTL = 30;

    public static function create(string $env = Constant::ENV_PROD, bool $debug = false): KernelInterface
    {
        /** @var string $root */
        $root = APP_ROOT;

        $envRegistry = array_merge(new DotEnv($root)->load(), getenv());
        $config = new Config(configDir: $root . DIRECTORY_SEPARATOR . APP_CONFIG, environment: $env, env: $envRegistry);

        $container = new Container(strictComplianceScan: $env === Constant::ENV_DEV);

        // --- PSR-17 -----------------------------------------------------------
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();
        $requestFactory = new RequestFactory();
        $container->set(ResponseFactoryInterface::class, $responseFactory);
        $container->set(StreamFactoryInterface::class, $streamFactory);
        $container->set(RequestFactoryInterface::class, $requestFactory);
        $container->set(ConfigInterface::class, $config);

        // --- Le client amont --------------------------------------------------
        // Aucun SsrfGuard n'est câblé, et c'est un choix, pas un oubli : la
        // destination est FIXÉE par l'exploitant (`gateway.upstream`), jamais
        // par le client — ProxyController ne reprend du message entrant que le
        // chemin et la query. Un guard SSRF rejetterait au contraire l'adressage
        // interne qui est précisément la raison d'être d'une passerelle
        // (`http://legacy:80` n'est pas routable publiquement).
        $client = new Client($responseFactory, $streamFactory);

        $upstream = $config->getString('gateway.upstream') ?? 'http://legacy:80';
        $container->set(
            ProxyController::class,
            new ProxyController(
                client: $client,
                requestFactory: $requestFactory,
                upstream: new UriFactory()->createUri($upstream),
            ),
        );

        // --- Le Shield --------------------------------------------------------
        $cache = new CacheFactory()->create(
            $config->getString('waffle.cache.adapter') ?? 'array',
            self::cacheOptions($config, $root),
        );
        $container->set(CacheInterface::class, $cache);
        $container->set(
            ResponseCache::class,
            new ResponseCache(
                cache: $cache,
                streams: $streamFactory,
                ttlSeconds: $config->getInt('gateway.shield.ttl') ?? self::DEFAULT_CACHE_TTL,
                maxBodyBytes: $config->getInt('gateway.shield.max_body_bytes') ?? self::DEFAULT_MAX_CACHED_BODY,
            ),
        );

        // --- Sécurité ---------------------------------------------------------
        $security = new Security($config);
        $securityContext = new AnonymousSecurityContext();
        $container->set(SecurityContextInterface::class, $securityContext);
        $secureContainer = new SecureContainer($container, $security, $securityContext);

        // --- Pipeline ---------------------------------------------------------
        $logger = new StreamLogger();
        $stack = new MiddlewareStack();

        // Prepend : il doit envelopper tout ce qui suit, y compris le routage.
        $stack->prepend(middleware: new ErrorHandlerMiddleware(
            renderer: new JsonErrorRenderer($responseFactory, $debug),
            logger: $logger,
        ));

        // `getArray()` rend un tableau non typé : le middleware attend une
        // list<string>, et une entrée non-chaîne dans l'allow-list serait un
        // trou de sécurité silencieux plutôt qu'une erreur de configuration.
        $trustedHosts = array_values(array_filter($config->getArray('waffle.trusted_hosts') ?? [], is_string(...)));
        if ($trustedHosts !== []) {
            $stack->add(middleware: new TrustedHostMiddleware($trustedHosts));
        }

        $controllersPath = $config->getString('waffle.paths.controllers') ?? throw new RuntimeException(
            'Missing "waffle.paths.controllers" in config/app.yaml.',
        );

        $router = new Router($root . DIRECTORY_SEPARATOR . $controllersPath, $cache);
        $router->boot(container: $secureContainer);

        $stack->add(middleware: new CoreRoutingMiddleware($router, $responseFactory));
        $stack->add(middleware: new SecurityMiddleware(secureContainer: $secureContainer, logger: $logger));
        $stack->add(middleware: new SecureHeadersMiddleware());

        // Le contrôleur attrape-tout est résolu par le conteneur : ses
        // collaborateurs (proxy + shield) sont déjà enregistrés ci-dessus.
        $container->set(GatewayController::class, new GatewayController());

        return new Kernel(
            config: $config,
            container: $secureContainer,
            security: $security,
            middlewareStack: $stack,
            logger: $logger,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function cacheOptions(ConfigInterface $config, string $root): array
    {
        return [
            'directory' => $root . '/var/cache/shield',
            'dsn' => $config->getString('waffle.cache.dsn') ?? 'redis://redis:6379',
            'prefix' => 'ecoshield:',
            'default_ttl' => $config->getInt('gateway.shield.ttl') ?? self::DEFAULT_CACHE_TTL,
        ];
    }
}
