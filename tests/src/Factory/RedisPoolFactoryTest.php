<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\RedisPoolFactory;
use AppTests\Helper\TestRedis;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;

/**
 * Le pool Redis de l'état partagé (NET-01/03) : construit sans connexion, il prête
 * ensuite un client phpredis vivant sur le Redis du DSN.
 */
#[CoversClass(RedisPoolFactory::class)]
final class RedisPoolFactoryTest extends TestCase
{
    #[Test]
    public function an_unreadable_dsn_is_refused_without_repeating_it(): void
    {
        try {
            // Port hors plage : parse_url() refuse le DSN.
            RedisPoolFactory::create('redis://:dsn-secret@redis:99999');
            self::fail('Un DSN illisible doit être refusé.');
        } catch (InvalidArgumentException $refus) {
            self::assertStringNotContainsString('dsn-secret', $refus->getMessage());
        }
    }

    #[Test]
    public function the_pool_opens_no_connection_before_its_first_lease(): void
    {
        // Hôte inexistant : une connexion ouverte à la construction lèverait ici.
        $pool = RedisPoolFactory::create('redis://redis.invalid:6379');

        self::assertSame(0, $pool->idleCount());
        self::assertSame(0, $pool->activeCount());
    }

    #[Test]
    public function a_lease_is_a_live_client_and_a_reused_one_is_probed_first(): void
    {
        $pool = RedisPoolFactory::create(TestRedis::dsn());

        $first = $pool->acquire();
        $client = $first->client();
        self::assertInstanceOf(Redis::class, $client);
        self::assertTrue($client->ping());
        $pool->release($first);

        // La connexion inactive repasse par la sonde PING avant d'être prêtée à nouveau.
        $second = $pool->acquire();
        self::assertSame($client, $second->client());
        $pool->release($second);
        self::assertSame(1, $pool->idleCount());
    }

    #[Test]
    public function the_dsn_password_is_sent_with_auth(): void
    {
        // Le Redis des tests n'a pas de mot de passe : c'est AUTH qui répond.
        $parts = parse_url(TestRedis::dsn());
        $host = is_array($parts) ? $parts['host'] ?? 'waffle-redis' : 'waffle-redis';
        $port = is_array($parts) ? $parts['port'] ?? 6379 : 6379;
        $pool = RedisPoolFactory::create(sprintf('redis://:pas-le-bon@%s:%d', $host, $port));

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageMatches('/AUTH/');

        $pool->acquire();
    }
}
