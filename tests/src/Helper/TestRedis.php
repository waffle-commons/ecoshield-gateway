<?php

declare(strict_types=1);

namespace AppTests\Helper;

/**
 * Le Redis des tests qui en ont besoin : le pool phpredis, le disjoncteur et le
 * limiteur ne se prouvent que contre un vrai serveur (scripts Lua atomiques).
 *
 * `ECOSHIELD_TEST_REDIS_DSN` le désigne. phpunit.xml le règle sur le service
 * `waffle-redis` du monorepo, où tournent les portes de qualité ; la CI de la
 * passerelle le remplace par son propre service Redis.
 */
final class TestRedis
{
    private const string FALLBACK = 'redis://waffle-redis:6379';

    public static function dsn(): string
    {
        $dsn = getenv('ECOSHIELD_TEST_REDIS_DSN');

        return is_string($dsn) && $dsn !== '' ? $dsn : self::FALLBACK;
    }
}
