<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\RateLimitFactory;
use App\Security\AnonymousSecurityContext;
use AppTests\Helper\StubConfig;
use AppTests\Helper\TestRedis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\ServerRequestFactory;
use Waffle\Commons\Security\Middleware\RateLimitMiddleware;

/**
 * Le limiteur de TOUT le trafic (NET-01) : un seau par IP cliente dans Redis,
 * l'IP n'étant lue dans X-Forwarded-For que derrière un proxy de confiance.
 *
 * Chaque test tire ses adresses au hasard (plage de documentation 2001:db8::/32) :
 * aucun seau n'est hérité d'une exécution précédente sur le même Redis.
 */
#[CoversClass(RateLimitFactory::class)]
final class RateLimitFactoryTest extends TestCase
{
    #[Test]
    public function there_is_no_middleware_unless_the_limiter_is_enabled(): void
    {
        foreach ([[], ['waffle.security.rate_limit.enabled' => 'false']] as $values) {
            self::assertNull(RateLimitFactory::middleware(
                new StubConfig($values),
                new AnonymousSecurityContext(),
                new ResponseFactory(),
                new NullLogger(),
            ));
        }
    }

    #[Test]
    public function trusted_proxies_are_a_comma_separated_list(): void
    {
        self::assertSame([], RateLimitFactory::trustedProxies(new StubConfig()));
        self::assertSame(
            ['10.0.0.0/8', '192.168.1.1'],
            RateLimitFactory::trustedProxies(new StubConfig([
                'gateway.trusted_proxies' => ' 10.0.0.0/8, 192.168.1.1 ,,',
            ])),
        );
    }

    #[Test]
    public function a_client_beyond_its_bucket_gets_429_with_retry_after(): void
    {
        $limiter = self::limiter(['waffle.security.rate_limit.capacity' => '2']);
        $client = self::address();

        $first = $limiter->process(self::request($client), self::ok());
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('2', $first->getHeaderLine('RateLimit-Limit'));
        self::assertSame('1', $first->getHeaderLine('RateLimit-Remaining'));

        self::assertSame(200, $limiter->process(self::request($client), self::ok())->getStatusCode());

        $refused = $limiter->process(self::request($client), self::ok());
        self::assertSame(429, $refused->getStatusCode());
        self::assertNotSame('', $refused->getHeaderLine('Retry-After'));
        self::assertSame('0', $refused->getHeaderLine('RateLimit-Remaining'));
    }

    #[Test]
    public function behind_a_trusted_proxy_each_forwarded_client_has_its_own_bucket(): void
    {
        $proxy = self::address();
        $limiter = self::limiter([
            'waffle.security.rate_limit.capacity' => '1',
            'gateway.trusted_proxies' => $proxy,
        ]);
        $alice = self::address();

        self::assertSame(200, $limiter->process(self::request($proxy, $alice), self::ok())->getStatusCode());
        self::assertSame(429, $limiter->process(self::request($proxy, $alice), self::ok())->getStatusCode());
        self::assertSame(200, $limiter->process(self::request($proxy, self::address()), self::ok())->getStatusCode());
    }

    #[Test]
    public function an_untrusted_peer_cannot_buy_a_fresh_bucket_with_x_forwarded_for(): void
    {
        $limiter = self::limiter(['waffle.security.rate_limit.capacity' => '1']);
        $client = self::address();

        self::assertSame(200, $limiter->process(self::request($client, self::address()), self::ok())->getStatusCode());
        self::assertSame(429, $limiter->process(self::request($client, self::address()), self::ok())->getStatusCode());
    }

    #[Test]
    public function an_unreachable_redis_lets_traffic_through_by_default(): void
    {
        // Rien n'écoute sur le port 1 : la connexion est refusée sur-le-champ.
        $limiter = self::limiter(['waffle.security.rate_limit.redis_dsn' => 'redis://127.0.0.1:1']);

        self::assertSame(200, $limiter->process(self::request(self::address()), self::ok())->getStatusCode());
    }

    #[Test]
    public function a_closed_limiter_refuses_with_503_when_redis_is_unreachable(): void
    {
        $limiter = self::limiter([
            'waffle.security.rate_limit.redis_dsn' => 'redis://127.0.0.1:1',
            'waffle.security.rate_limit.on_storage_failure' => 'closed',
        ]);

        $refused = $limiter->process(self::request(self::address()), self::ok());

        self::assertSame(503, $refused->getStatusCode());
        self::assertNotSame('', $refused->getHeaderLine('Retry-After'));
    }

    /**
     * @param array<string, string> $overrides
     */
    private static function limiter(array $overrides): RateLimitMiddleware
    {
        $limiter = RateLimitFactory::middleware(
            new StubConfig([
                'waffle.security.rate_limit.enabled' => 'true',
                'waffle.security.rate_limit.redis_dsn' => TestRedis::dsn(),
                ...$overrides,
            ]),
            new AnonymousSecurityContext(),
            new ResponseFactory(),
            new NullLogger(),
        );
        self::assertNotNull($limiter);

        return $limiter;
    }

    private static function request(string $peer, ?string $forwardedFor = null): ServerRequestInterface
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', 'http://localhost/api/orders/1', [
            'REMOTE_ADDR' => $peer,
        ]);

        return $forwardedFor === null ? $request : $request->withHeader('X-Forwarded-For', $forwardedFor);
    }

    private static function address(): string
    {
        return sprintf('2001:db8:%x:%x::%x', random_int(0, 0xffff), random_int(0, 0xffff), random_int(1, 0xffff));
    }

    private static function ok(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new ResponseFactory()->createResponse(200);
            }
        };
    }
}
