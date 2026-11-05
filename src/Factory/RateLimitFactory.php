<?php

declare(strict_types=1);

namespace App\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\RateLimit\TokenBucket;
use Waffle\Commons\Data\RateLimit\RedisLimiterStateStorage;
use Waffle\Commons\Http\ClientIp\TrustedProxyClientIpResolver;
use Waffle\Commons\Security\Middleware\RateLimitMiddleware;
use Waffle\Commons\Security\RateLimit\RateLimiter;
use Waffle\Commons\Security\RateLimit\StorageFailureMode;

/**
 * Limitation de débit de TOUT le trafic (NET-01, RFC-017 §3.1), routes reprises
 * comme proxyfiées : la passerelle protège aussi le monolithe des rafales, ce
 * qu'un PHP-FPM qui reconstruit son framework à chaque requête ne sait pas faire.
 *
 * Un seau à jetons par IP cliente, partagé dans Redis, jamais en mémoire du
 * worker. La passerelle n'authentifie personne : il n'y a pas de sujet, seulement
 * l'adresse, lue derrière les proxys de `gateway.trusted_proxies`. Derrière un
 * répartiteur de charge non déclaré, tous les clients partageraient son seau.
 * Redis indisponible : `on_storage_failure` (open par défaut, la disponibilité
 * prime, avec un avertissement dans le journal).
 */
final class RateLimitFactory
{
    private const string KEY = 'waffle.security.rate_limit.';

    /** Préfixe des seaux dans Redis, à côté du `ecoshield:` du Shield. */
    private const string BUCKET_PREFIX = 'ecoshield:ratelimit:';

    /** Pas de middleware (null) quand `waffle.security.rate_limit.enabled` n'est pas vrai. */
    public static function middleware(
        ConfigInterface $config,
        SecurityContextInterface $securityContext,
        ResponseFactoryInterface $responseFactory,
        LoggerInterface $logger,
    ): ?RateLimitMiddleware {
        if ($config->getBool(self::KEY . 'enabled') !== true) {
            return null;
        }

        $limiter = new RateLimiter(
            new RedisLimiterStateStorage(
                RedisPoolFactory::create($config->getString(self::KEY . 'redis_dsn') ?? 'redis://redis:6379'),
                self::BUCKET_PREFIX,
            ),
            new TokenBucket(
                $config->getInt(self::KEY . 'capacity') ?? 600,
                $config->getInt(self::KEY . 'period_seconds') ?? 60,
            ),
            StorageFailureMode::tryFrom($config->getString(self::KEY . 'on_storage_failure') ?? '')
            ?? StorageFailureMode::Open,
            $logger,
        );

        return new RateLimitMiddleware(
            $limiter,
            new TrustedProxyClientIpResolver(self::trustedProxies($config)),
            $securityContext,
            $responseFactory,
        );
    }

    /**
     * `TRUSTED_PROXIES` : adresses ou plages CIDR séparées par des virgules.
     * `%env(...)%` rend une chaîne, ou `null` quand la variable n'existe pas ;
     * les entrées vides sont ignorées.
     *
     * @return list<string>
     */
    public static function trustedProxies(ConfigInterface $config): array
    {
        $proxies = [];
        foreach (explode(',', $config->getString('gateway.trusted_proxies') ?? '') as $entry) {
            $proxy = mb_trim($entry);
            if ($proxy === '') {
                continue;
            }

            $proxies[] = $proxy;
        }

        return $proxies;
    }
}
