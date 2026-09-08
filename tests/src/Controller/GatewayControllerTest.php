<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\GatewayController;
use App\Proxy\ProxyController;
use App\Shield\ResponseCache;
use AppTests\Helper\RecordingClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Cache\Adapter\ArrayCache;
use Waffle\Commons\Http\Factory\RequestFactory;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\ServerRequestFactory;
use Waffle\Commons\Http\Factory\StreamFactory;
use Waffle\Commons\Http\Factory\UriFactory;

/**
 * Piliers 2 et 3 réunis : l'attrape-tout consulte le cache AVANT le réseau et
 * l'alimente APRÈS. La propriété qui compte est qu'un hit ne touche pas l'amont
 * — c'est toute la charge épargnée au monolithe.
 */
#[CoversClass(GatewayController::class)]
final class GatewayControllerTest extends TestCase
{
    private function controller(): GatewayController
    {
        $controller = new GatewayController();
        $controller->setResponseFactory(new ResponseFactory());

        return $controller;
    }

    private function proxy(RecordingClient $client): ProxyController
    {
        return new ProxyController(
            client: $client,
            requestFactory: new RequestFactory(),
            upstream: new UriFactory()->createUri('http://legacy.internal:80'),
        );
    }

    private function shield(): ResponseCache
    {
        return new ResponseCache(new ArrayCache(), new StreamFactory(), ttlSeconds: 30, maxBodyBytes: 262_144);
    }

    private function request(string $method = 'GET', string $uri = 'http://gw.local/catalogue'): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest($method, $uri);
    }

    #[Test]
    public function the_first_call_reaches_the_monolith_and_the_second_does_not(): void
    {
        $upstream = new ResponseFactory()
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new StreamFactory()->createStream('{"served_by":"legacy-monolith"}'));

        $client = new RecordingClient($upstream);
        $controller = $this->controller();
        $proxy = $this->proxy($client);
        $shield = $this->shield();

        $first = $controller->proxy($this->request(), $proxy, $shield);
        self::assertSame('MISS', $first->getHeaderLine('X-EcoShield-Cache'));
        $sent = $client->lastRequest();
        self::assertNotNull($sent);
        self::assertSame('legacy.internal', $sent->getUri()->getHost());

        // Un client neuf : s'il enregistre quoi que ce soit, le hit a menti.
        $secondClient = new RecordingClient($upstream);
        $second = $controller->proxy($this->request(), $this->proxy($secondClient), $shield);

        self::assertSame('HIT', $second->getHeaderLine('X-EcoShield-Cache'));
        self::assertSame('{"served_by":"legacy-monolith"}', (string) $second->getBody());
        self::assertNull($secondClient->lastRequest(), 'un hit de cache ne doit jamais toucher le monolithe');
    }

    #[Test]
    public function a_write_always_reaches_the_monolith(): void
    {
        $upstream = new ResponseFactory()
            ->createResponse(200)
            ->withBody(new StreamFactory()->createStream('{"created":true}'));

        $shield = $this->shield();
        $controller = $this->controller();

        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $client = new RecordingClient($upstream);
            $response = $controller->proxy($this->request($method), $this->proxy($client), $shield);

            self::assertSame('BYPASS', $response->getHeaderLine('X-EcoShield-Cache'));
            $sent = $client->lastRequest();
            self::assertNotNull($sent, $method . ' doit atteindre le monolithe');
            self::assertSame($method, $sent->getMethod());
        }
    }
}
