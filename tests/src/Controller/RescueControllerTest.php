<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\RescueController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Http\Factory\ResponseFactory;

/**
 * Pilier 1 : les routes reprises se servent sans jamais toucher au legacy.
 */
#[CoversClass(RescueController::class)]
final class RescueControllerTest extends TestCase
{
    private function controller(): RescueController
    {
        $controller = new RescueController();
        $controller->setResponseFactory(new ResponseFactory());

        return $controller;
    }

    #[Test]
    public function health_reports_the_gateway_alone(): void
    {
        $response = $this->controller()->health();

        self::assertSame(200, $response->getStatusCode());

        /** @var array{status: string, gateway: string, mode: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        // La sonde ne dit rien de l'amont, et c'est délibéré : redémarrer la
        // passerelle parce que le monolithe est tombé aggrave l'incident.
        self::assertSame('worker', $payload['mode']);
    }

    #[Test]
    public function a_rescued_route_is_served_by_the_gateway(): void
    {
        $response = $this->controller()->product('42');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{id: string, name: string, served_by: string, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('42', $payload['id']);
        self::assertSame('ecoshield-gateway', $payload['served_by']);
    }
}
