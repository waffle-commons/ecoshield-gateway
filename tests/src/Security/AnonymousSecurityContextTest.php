<?php

declare(strict_types=1);

namespace AppTests\Security;

use App\Security\AnonymousSecurityContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Contracts\Auth\UserIdentityInterface;

/**
 * Le contexte de la passerelle est anonyme par défaut et vidé entre deux
 * requêtes — sans quoi une identité publiée par une requête resterait visible
 * pour la suivante, ce qui est exactement la fuite que le mode worker rend
 * possible et qu'`igor` cherche.
 */
#[CoversClass(AnonymousSecurityContext::class)]
final class AnonymousSecurityContextTest extends TestCase
{
    private function identity(): UserIdentityInterface
    {
        return new class implements UserIdentityInterface {
            public string $subject = 'demo';

            public ?string $email = null;

            /** @var list<string> */
            public array $roles = [];

            /** @var array<string, mixed> */
            public array $claims = [];
        };
    }

    #[Test]
    public function a_fresh_context_is_anonymous(): void
    {
        $context = new AnonymousSecurityContext();

        self::assertFalse($context->isAuthenticated());
        self::assertNull($context->getIdentity());
        self::assertNull($context->getClientIp());
    }

    #[Test]
    public function a_published_identity_is_readable_for_the_request(): void
    {
        $context = new AnonymousSecurityContext();
        $identity = $this->identity();

        $context->authenticate($identity, '203.0.113.7');

        self::assertTrue($context->isAuthenticated());
        self::assertSame($identity, $context->getIdentity());
        self::assertSame('203.0.113.7', $context->getClientIp());
    }

    #[Test]
    public function reset_returns_the_context_to_anonymous(): void
    {
        $context = new AnonymousSecurityContext();
        $context->authenticate($this->identity(), '203.0.113.7');

        $context->reset();

        self::assertFalse($context->isAuthenticated());
        self::assertNull($context->getIdentity());
        self::assertNull($context->getClientIp());
    }
}
