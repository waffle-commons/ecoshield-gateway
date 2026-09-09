<?php

declare(strict_types=1);

namespace AppTests\Helper;

use Waffle\Commons\Contracts\Config\ConfigInterface;

/**
 * Configuration en dur, pour les tests qui n'ont besoin que d'une clé.
 *
 * Une doublure concrète plutôt qu'un mock : PHPUnit 12.5 signale les mocks sans
 * attente, et une configuration n'a rien à vérifier — elle a des valeurs à
 * rendre. Le stub reproduit fidèlement le comportement qui compte ici : une clé
 * absente rend le défaut, jamais une exception.
 */
final readonly class StubConfig implements ConfigInterface
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        private array $values = [],
    ) {}

    #[\Override]
    public function getInt(string $key, ?int $default = null): ?int
    {
        // `??` plutôt que `isset()` : le linter refuse `isset()` parce qu'il
        // confond « clé absente » et « valeur nulle ». Ici les deux doivent
        // rendre le défaut, et `??` le dit sans ambiguïté.
        $value = $this->values[$key] ?? null;

        return $value === null ? $default : (int) $value;
    }

    #[\Override]
    public function getString(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    #[\Override]
    public function getArray(string $key, ?array $default = null): ?array
    {
        return $default;
    }

    #[\Override]
    public function getBool(string $key, ?bool $default = null): ?bool
    {
        $value = $this->values[$key] ?? null;

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
