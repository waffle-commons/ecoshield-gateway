<?php

declare(strict_types=1);

namespace App\Security;

use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Auth\UserIdentityInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;

/**
 * Contexte de sécurité de la passerelle — anonyme par conception.
 *
 * EcoShield est un proxy d'infrastructure : il ne porte AUCUNE authentification.
 * L'identité reste celle que le monolithe legacy connaît, transmise telle quelle
 * dans les en-têtes ; la passerelle ne la valide pas et ne prétend pas le faire.
 * L'authentification de bord (JWT / OIDC / assertions) est un sujet beta7
 * (`Roadmap_Beta7` AXE 1) : le jour où elle arrive, `waffle-commons/auth` fournit
 * le vrai `SecurityContext` et cette classe disparaît.
 *
 * Le `SecureContainer` exige néanmoins un contexte non nul. Celui-ci le satisfait
 * sans mentir sur ce qui est vérifié : `isAuthenticated()` répond `false` tant
 * qu'aucune identité n'est publiée.
 *
 * Worker-safety : l'état est à portée requête, donc {@see ResettableInterface}
 * est déclaré DIRECTEMENT (igor fait un scan superficiel : l'hériter via
 * `SecurityContextInterface` ne suffirait pas au gate).
 */
final class AnonymousSecurityContext implements SecurityContextInterface, ResettableInterface
{
    private ?UserIdentityInterface $identity = null;

    private ?string $clientIp = null;

    #[\Override]
    public function authenticate(UserIdentityInterface $identity, ?string $clientIp = null): void
    {
        $this->identity = $identity;
        $this->clientIp = $clientIp;
    }

    #[\Override]
    public function isAuthenticated(): bool
    {
        return $this->identity !== null;
    }

    #[\Override]
    public function getIdentity(): ?UserIdentityInterface
    {
        return $this->identity;
    }

    #[\Override]
    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    #[\Override]
    public function reset(): void
    {
        $this->identity = null;
        $this->clientIp = null;
    }
}
