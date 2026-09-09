<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\RescueController;
use AppTests\Helper\StubConfig;
use AppTests\Helper\StubConnectionPool;
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

    #[Test]
    public function a_rescued_db_route_returns_the_seeded_row(): void
    {
        $pool = new StubConnectionPool([
            ['id' => 'abc-123', 'email' => 'user1@bench.ecoshield.local'],
        ]);

        $response = $this->controller()->user('abc-123', $pool);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{found: bool, user: array{id: string, email: string}|null, served_by: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['found']);
        self::assertNotNull($payload['user']);
        self::assertSame('user1@bench.ecoshield.local', $payload['user']['email']);
        self::assertSame('ecoshield-gateway', $payload['served_by']);
    }

    #[Test]
    public function an_unknown_id_is_a_200_not_a_404(): void
    {
        // Délibéré, et c'est une contrainte de MESURE : le banc compare des
        // percentiles de latence, et deux statuts différents mélangeraient deux
        // distributions sous un même chiffre.
        $response = $this->controller()->user('inconnu', new StubConnectionPool());

        self::assertSame(200, $response->getStatusCode());

        /** @var array{found: bool, user: null} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['found']);
        self::assertNull($payload['user']);
    }

    #[Test]
    public function an_unreachable_database_degrades_to_503_and_never_leaks(): void
    {
        // Sans base configurée, la passerelle reste parfaitement capable de
        // proxyfier et de servir son cache : seule CETTE route est privée de sa
        // source. C'est ce qui permet à la démonstration par défaut de tourner
        // sans PostgreSQL.
        $response = $this->controller()->user('abc-123', new StubConnectionPool(unreachable: true));

        self::assertSame(503, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringContainsString('database_unavailable', $body);
        // Aucune trace, aucun DSN, aucun message du pilote : un 503 qui raconte
        // la topologie interne est une fuite d'information.
        self::assertStringNotContainsString('no healthy connection', $body);
    }

    #[Test]
    public function the_memory_probe_is_closed_unless_diagnostics_are_enabled(): void
    {
        // Absence de la clé = fermeture. C'est le cas par défaut en production :
        // publier l'empreinte mémoire renseigne un attaquant sur l'effet de ses
        // requêtes, et un défaut qui s'ouvre tout seul serait le vrai défaut.
        $response = $this->controller()->memory(new StubConfig());

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function an_explicitly_falsey_flag_keeps_the_memory_probe_closed(): void
    {
        // `%env(...)%` rend une chaîne : "0" et "false" doivent fermer, sans quoi
        // toute valeur non vide ouvrirait la sonde.
        foreach (['0', 'false', ''] as $flag) {
            $response = $this->controller()->memory(new StubConfig(['gateway.diagnostics' => $flag]));

            self::assertSame(404, $response->getStatusCode(), sprintf('le drapeau "%s" doit fermer la sonde', $flag));
        }
    }

    #[Test]
    public function the_memory_probe_reports_both_resolutions_of_the_worker_heap(): void
    {
        $response = $this->controller()->memory(new StubConfig(['gateway.diagnostics' => '1']));

        self::assertSame(200, $response->getStatusCode());

        /** @var array{heap_bytes: int, heap_real_bytes: int, peak_bytes: int, peak_real_bytes: int} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        // Le tas fin est mesuré à l'octet, le tas « réel » par blocs réclamés à
        // l'OS : le second borne donc toujours le premier. Un relevé qui
        // violerait cet ordre signalerait qu'on lit deux grandeurs sans rapport.
        self::assertGreaterThan(0, $payload['heap_bytes']);
        self::assertGreaterThanOrEqual($payload['heap_bytes'], $payload['heap_real_bytes']);
        self::assertGreaterThanOrEqual($payload['heap_bytes'], $payload['peak_bytes']);
        self::assertGreaterThanOrEqual($payload['peak_bytes'], $payload['peak_real_bytes']);
    }
}
