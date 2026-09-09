<?php

declare(strict_types=1);

namespace AppTests\Helper;

use PDO;
use RuntimeException;
use Waffle\Commons\Contracts\Data\Connection\ConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\ConnectionKind;
use Waffle\Commons\Contracts\Data\Connection\PdoConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;

/**
 * Pool de connexions de test, adossé à un SQLite en mémoire.
 *
 * Une doublure CONCRÈTE plutôt qu'un mock : la route testée exécute une vraie
 * requête préparée, et un mock ne dirait que ce qu'on lui a soufflé. SQLite en
 * mémoire exécute réellement le `SELECT ... WHERE id = ?` — si la requête est
 * malformée ou la colonne mal nommée, le test le voit, ce qu'aucune attente
 * pré-déclarée ne montrerait.
 *
 * Le moteur diffère de la production (PostgreSQL), et c'est acceptable ici :
 * ce qui est vérifié est le comportement du CONTRÔLEUR — trouvé, non trouvé,
 * source injoignable — pas la compatibilité d'un dialecte. Le banc, lui, mesure
 * sur PostgreSQL.
 */
final class StubConnectionPool implements RelationalConnectionPoolInterface
{
    private readonly ?PDO $pdo;

    /**
     * @param array<int, array{id: string, email: string}> $rows Lignes à amorcer.
     * @param bool $unreachable Simule une base absente ou injoignable.
     */
    public function __construct(
        array $rows = [],
        // NON promu : la valeur ne sert qu'ici, dans le constructeur. La promouvoir
        // en propriété créerait un champ que rien ne relit ensuite, ce que
        // l'analyseur signale à juste titre.
        bool $unreachable = false,
    ) {
        if ($unreachable) {
            $this->pdo = null;

            return;
        }

        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id VARCHAR(36) PRIMARY KEY, email VARCHAR(255), created_at TEXT)');

        // `PDO::prepare()` est typé `PDOStatement|false`. En mode exception il
        // lève plutôt qu'il ne rend `false`, mais la signature ne le sait pas :
        // le garde est là pour le type, pas pour un cas qui se produirait.
        $insert = $pdo->prepare('INSERT INTO users (id, email, created_at) VALUES (?, ?, ?)');
        if ($insert === false) {
            throw new RuntimeException('impossible de préparer l amorçage du jeu de test');
        }

        foreach ($rows as $row) {
            $insert->execute([$row['id'], $row['email'], '2026-01-01 00:00:00']);
        }

        $this->pdo = $pdo;
    }

    #[\Override]
    public function acquire(): PdoConnectionInterface
    {
        if ($this->pdo === null) {
            // Le pool réel lève quand aucune connexion saine ne peut être
            // établie. Le contrôleur doit répondre 503 plutôt que de propager.
            throw new RuntimeException('no healthy connection');
        }

        return new StubPdoConnection($this->pdo);
    }

    #[\Override]
    public function release(ConnectionInterface $connection): void {}

    #[\Override]
    public function beginRequestScope(): PdoConnectionInterface
    {
        return $this->acquire();
    }

    #[\Override]
    public function endRequestScope(): void {}
}

/**
 * Bail PDO de test — la portion de {@see PdoConnectionInterface} dont la route
 * se sert réellement.
 */
final readonly class StubPdoConnection implements PdoConnectionInterface
{
    public function __construct(
        private PDO $pdo,
    ) {}

    #[\Override]
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    #[\Override]
    public function kind(): ConnectionKind
    {
        return ConnectionKind::Pdo;
    }

    #[\Override]
    public function isAlive(): bool
    {
        return true;
    }

    #[\Override]
    public function id(): int
    {
        return 1;
    }
}
