<?php

declare(strict_types=1);

namespace App\Factory;

use PDO;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Data\Connection\PDOConnectionPool;

/**
 * Le pool de connexions des routes reprises (RFC-022).
 *
 * ## Pourquoi une passerelle a fini par avoir une base
 *
 * Elle n'en avait pas, et l'argument tenait : un proxy n'a rien à interroger.
 * Il cesse de tenir dès qu'une route est REPRISE — servir `/api/users/{id}`
 * depuis le worker suppose d'aller chercher l'utilisateur quelque part, et une
 * reprise qui ne reprend pas la donnée ne reprend rien du tout.
 * {@see \App\Controller\RescueController} l'annonçait déjà en toutes lettres.
 *
 * ## Paresseux, et c'est le point
 *
 * La fabrique injectée n'ouvre une connexion que lorsque le pool en a besoin.
 * Sans `DB_HOST`, la passerelle démarre, sert le proxy et le Shield, et n'ouvre
 * jamais rien : l'ajout d'une base ne coûte donc RIEN à la démonstration par
 * défaut, et personne n'a à installer PostgreSQL pour lancer le POC.
 *
 * En mode worker, ce même caractère paresseux est ce qui rend les sockets
 * tièdes d'une requête à l'autre : la connexion survit à la requête qui l'a
 * ouverte, est sondée avant d'être redistribuée, et reconnectée de façon
 * transparente si le serveur l'a fermée entre-temps. C'est exactement le coût
 * que PHP-FPM repaie à chaque requête.
 */
final class ConnectionPoolFactory
{
    /** Plafond par défaut, aligné sur celui de PDOConnectionPool. */
    private const int DEFAULT_POOL_SIZE = 8;

    /** Fenêtre de fraîcheur par défaut de la sonde, en millisecondes (squelette : 500). */
    private const int DEFAULT_PING_FRESHNESS_MS = 500;

    public static function create(ConfigInterface $config): PDOConnectionPool
    {
        $driver = $config->getString('waffle.database.driver') ?? 'pgsql';
        $host = $config->getString('waffle.database.host') ?? '';
        $port = $config->getString('waffle.database.port') ?? '5432';
        $database = $config->getString('waffle.database.database') ?? '';
        $username = $config->getString('waffle.database.username') ?? '';
        $password = $config->getString('waffle.database.password') ?? '';

        $dsn = sprintf('%s:host=%s;port=%s;dbname=%s', $driver, $host, $port, $database);

        // Fabrique sans état, rejouée par le pool à chaque ouverture. Elle n'est
        // PAS appelée ici : tant que personne n'emprunte de connexion, aucune
        // socket n'est ouverte et une configuration absente reste sans effet.
        return new PDOConnectionPool(factory: static fn(): PDO => new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]), maxConnections: self::resolvePoolSize($config), pingQuery: 'SELECT 1', pingFreshnessMs: self::resolvePingFreshness($config));
    }

    /**
     * Taille du pool, par worker.
     *
     * `%env(...)%` rend une CHAÎNE : absente, vide ou non entière, on retombe sur
     * le défaut plutôt que de propager un zéro qui bloquerait le pool au premier
     * emprunt.
     */
    public static function resolvePoolSize(ConfigInterface $config): int
    {
        return self::resolveInt($config, 'waffle.database.pool_size', 1, self::DEFAULT_POOL_SIZE);
    }

    /**
     * Fenêtre de fraîcheur de la sonde, en millisecondes (`[CARRY-01]` du
     * framework, `DB_PING_FRESHNESS_MS`) : une connexion qui a fait ses preuves
     * depuis moins longtemps est prêtée sans `SELECT 1`, ce qui retire un aller-
     * retour à la base par lecture reprise. 0 rétablit la sonde à chaque emprunt.
     * Absente, vide ou invalide : 500, comme le squelette du framework.
     */
    public static function resolvePingFreshness(ConfigInterface $config): int
    {
        return self::resolveInt($config, 'waffle.database.ping_freshness_ms', 0, self::DEFAULT_PING_FRESHNESS_MS);
    }

    /**
     * Un entier de configuration interpolé depuis l'environnement, donc une
     * chaîne. Absent, vide, non entier ou sous le minimum : le défaut — une
     * valeur fautive n'empêche jamais le boot.
     */
    private static function resolveInt(ConfigInterface $config, string $key, int $minimum, int $default): int
    {
        $raw = $config->getString($key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return is_int($value) && $value >= $minimum ? $value : $default;
    }
}
