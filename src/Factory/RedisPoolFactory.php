<?php

declare(strict_types=1);

namespace App\Factory;

use InvalidArgumentException;
use Redis;
use Waffle\Commons\Data\Connection\RedisConnectionPool;

/**
 * Pool phpredis de l'état partagé entre tous les workers : le circuit du
 * disjoncteur amont (NET-03) et les seaux du limiteur de débit (NET-01).
 *
 * Le Shield, lui, passe par le cache PSR-16 (`waffle.cache.*`, Predis), hors de
 * ce pool : deux usages de Redis, deux clients (RFC-024 §5.1). Même fabrique que
 * celle du squelette du framework, à la virgule près.
 */
final class RedisPoolFactory
{
    /**
     * Construit le pool à partir d'un DSN `redis://[:mot-de-passe@]hôte[:port]`.
     *
     * Paresseux comme le pool PDO : aucune connexion n'est ouverte avant le
     * premier emprunt, et la sonde `PING` écarte une connexion morte avant de la
     * prêter à nouveau.
     *
     * @throws InvalidArgumentException Si le DSN est illisible (son contenu n'est pas
     *                                  repris dans le message : il peut porter un mot de passe).
     */
    public static function create(string $dsn): RedisConnectionPool
    {
        $parts = parse_url($dsn);
        if ($parts === false) {
            throw new InvalidArgumentException('The Redis DSN cannot be parsed.');
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 6379;
        $password = array_key_exists('pass', $parts) ? rawurldecode($parts['pass']) : null;

        return new RedisConnectionPool(
            factory: static function () use ($host, $port, $password): Redis {
                $redis = new Redis();
                $redis->connect($host, $port, 1.0);
                if ($password !== null) {
                    $redis->auth($password);
                }

                return $redis;
            },
            healthCheck: static fn(object $client): bool => $client instanceof Redis && $client->ping() !== false,
        );
    }
}
