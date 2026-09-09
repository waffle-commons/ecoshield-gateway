<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\ConnectionPoolFactory;
use AppTests\Helper\StubConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Data\Connection\PDOConnectionPool;

/**
 * Le pool des routes reprises : paresseux, et tolérant à une configuration absente.
 */
#[CoversClass(ConnectionPoolFactory::class)]
final class ConnectionPoolFactoryTest extends TestCase
{
    #[Test]
    public function building_the_pool_opens_no_connection(): void
    {
        // LA propriété qui compte. L'hôte est volontairement inexistant : si la
        // fabrique ouvrait la connexion au moment de la construction, ce test
        // lèverait. Qu'il passe est la preuve que la démonstration par défaut —
        // sans PostgreSQL — démarre exactement comme avant.
        $pool = ConnectionPoolFactory::create(new StubConfig([
            'waffle.database.driver' => 'pgsql',
            'waffle.database.host' => 'hote.invalide',
            'waffle.database.port' => '5432',
            'waffle.database.database' => 'bench',
            'waffle.database.username' => 'bench',
            // Volontairement PAS d'identifiant secret dans la fixture : le
            // linter refuse toute valeur qui ressemble à un mot de passe en dur,
            // et il a raison même en test — un dépôt n'est pas l'endroit où
            // apprendre à en écrire. La propriété vérifiée ici (la paresse) ne
            // dépend d'aucune authentification, puisque rien ne se connecte.
        ]));

        self::assertInstanceOf(PDOConnectionPool::class, $pool);
    }

    #[Test]
    public function an_entirely_absent_configuration_still_builds(): void
    {
        // Aucune variable DB_* : c'est le cas du `docker-compose.yml` par défaut.
        self::assertInstanceOf(PDOConnectionPool::class, ConnectionPoolFactory::create(new StubConfig()));
    }

    #[Test]
    public function the_pool_size_falls_back_rather_than_propagating_a_bad_value(): void
    {
        // `%env(...)%` rend une CHAÎNE, et une valeur absurde ne doit pas se
        // propager : un pool de taille 0 bloquerait au premier emprunt, très loin
        // de la faute de configuration qui l'a causé.
        foreach (['', 'zéro', '0', '-4'] as $bad) {
            self::assertSame(
                8,
                ConnectionPoolFactory::resolvePoolSize(new StubConfig(['waffle.database.pool_size' => $bad])),
                sprintf('la valeur "%s" doit retomber sur le défaut', $bad),
            );
        }

        self::assertSame(8, ConnectionPoolFactory::resolvePoolSize(new StubConfig()));
    }

    #[Test]
    public function a_valid_pool_size_is_honoured(): void
    {
        self::assertSame(4, ConnectionPoolFactory::resolvePoolSize(new StubConfig([
            'waffle.database.pool_size' => '4',
        ])));
    }
}
