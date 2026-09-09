<?php

declare(strict_types=1);

namespace AppTests;

use App\Factory\AppKernelFactory;
use App\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Http\Factory\ServerRequestFactory;

/**
 * Test de fumée du boot.
 *
 * Les tests unitaires ci-à-côté prouvent chaque pièce isolément ; celui-ci
 * prouve qu'elles s'assemblent. C'est la différence qui compte : la passerelle
 * peut avoir toutes ses classes correctes et rester incapable de démarrer parce
 * qu'un service n'est pas enregistré, une route pas découverte ou un middleware
 * mal ordonné — exactement le genre de panne qu'un contrôleur testé en direct ne
 * verra jamais, puisqu'il court-circuite le pipeline.
 *
 * Aucun réseau n'est touché : seules les routes reprises sont exercées, et
 * l'amont configuré pointe volontairement vers un hôte invalide.
 */
#[CoversClass(AppKernelFactory::class)]
#[CoversClass(Kernel::class)]
final class BootSmokeTest extends TestCase
{
    #[Test]
    public function the_gateway_assembles(): void
    {
        self::assertInstanceOf(Kernel::class, AppKernelFactory::create(env: 'dev', debug: true));
    }

    #[Test]
    public function the_health_probe_answers_through_the_real_pipeline(): void
    {
        $kernel = AppKernelFactory::create(env: 'dev', debug: true);

        $response = $kernel->handle(
            new ServerRequestFactory()
                ->createServerRequest('GET', 'http://localhost/__ecoshield/health')
                ->withHeader('Host', 'localhost'),
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array{status: string, gateway: string, mode: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertSame('ecoshield', $payload['gateway']);
    }

    #[Test]
    public function a_rescued_route_is_resolved_by_the_router(): void
    {
        $kernel = AppKernelFactory::create(env: 'dev', debug: true);

        $response = $kernel->handle(
            new ServerRequestFactory()
                ->createServerRequest('GET', 'http://localhost/api/products/42')
                ->withHeader('Host', 'localhost'),
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array{id: string, served_by: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('42', $payload['id']);
        // La preuve que le routage a bien préféré la route reprise à
        // l'attrape-tout : le proxy n'aurait pas pu répondre, son amont est mort.
        self::assertSame('ecoshield-gateway', $payload['served_by']);
    }

    #[Test]
    public function an_untrusted_host_is_refused_before_anything_else(): void
    {
        $kernel = AppKernelFactory::create(env: 'dev', debug: true);

        $response = $kernel->handle(
            new ServerRequestFactory()
                ->createServerRequest('GET', 'http://evil.example/__ecoshield/health')
                ->withHeader('Host', 'evil.example'),
        );

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
    }
}
