<?php

declare(strict_types=1);

namespace AppTests\Shield;

use App\Shield\ResponseCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Cache\Adapter\ArrayCache;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\ServerRequestFactory;
use Waffle\Commons\Http\Factory\StreamFactory;

/**
 * Le Shield est jugé sur ce qu'il REFUSE de mettre en cache : un cache de
 * passerelle trop permissif ne dégrade pas les performances, il fait fuiter des
 * réponses privées d'un client vers un autre.
 */
#[CoversClass(ResponseCache::class)]
final class ResponseCacheTest extends TestCase
{
    private function shield(int $maxBodyBytes = 262_144): ResponseCache
    {
        return new ResponseCache(
            cache: new ArrayCache(),
            streams: new StreamFactory(),
            ttlSeconds: 30,
            maxBodyBytes: $maxBodyBytes,
        );
    }

    private function request(
        string $method = 'GET',
        string $uri = 'http://gw.local/catalogue',
    ): \Psr\Http\Message\ServerRequestInterface {
        return new ServerRequestFactory()->createServerRequest($method, $uri);
    }

    private function response(int $status = 200, string $body = '{"ok":true}'): ResponseInterface
    {
        return new ResponseFactory()
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new StreamFactory()->createStream($body));
    }

    #[Test]
    public function a_stored_response_is_replayed_body_and_headers_intact(): void
    {
        $shield = $this->shield();
        $request = $this->request();

        $stored = $shield->store($request, $this->response());
        self::assertSame('MISS', $stored->getHeaderLine('X-EcoShield-Cache'));
        // Le corps doit rester lisible APRÈS mise en cache : le consommer pour
        // l'enregistrer sans le reconstruire émettrait une réponse vide.
        self::assertSame('{"ok":true}', (string) $stored->getBody());

        $hit = $shield->lookup($request, new ResponseFactory()->createResponse());
        self::assertNotNull($hit);
        self::assertSame(200, $hit->getStatusCode());
        self::assertSame('{"ok":true}', (string) $hit->getBody());
        self::assertSame('application/json', $hit->getHeaderLine('Content-Type'));
        self::assertSame('HIT', $hit->getHeaderLine('X-EcoShield-Cache'));
    }

    #[Test]
    public function a_cold_cache_reports_no_hit(): void
    {
        self::assertNull($this->shield()->lookup($this->request(), new ResponseFactory()->createResponse()));
    }

    #[Test]
    public function only_get_and_head_are_shared(): void
    {
        $shield = $this->shield();

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $request = $this->request($method);
            self::assertSame('BYPASS', $shield->store($request, $this->response())->getHeaderLine('X-EcoShield-Cache'));
            self::assertNull($shield->lookup($request, new ResponseFactory()->createResponse()));
        }
    }

    #[Test]
    public function a_request_carrying_credentials_is_never_shared(): void
    {
        $shield = $this->shield();

        foreach (['Authorization' => 'Bearer x', 'Cookie' => 'sid=abc'] as $header => $value) {
            $request = $this->request()->withHeader($header, $value);

            // Ni stockée…
            self::assertSame('BYPASS', $shield->store($request, $this->response())->getHeaderLine('X-EcoShield-Cache'));
            // …ni servie depuis une entrée déposée par une requête anonyme.
            $shield->store($this->request(), $this->response(body: '{"anonyme":true}'));
            self::assertNull($shield->lookup($request, new ResponseFactory()->createResponse()));
        }
    }

    #[Test]
    public function only_a_plain_200_is_stored(): void
    {
        $shield = $this->shield();

        foreach ([201, 204, 301, 404, 500] as $status) {
            $request = $this->request(uri: 'http://gw.local/s' . $status);
            $shield->store($request, $this->response($status));
            self::assertNull($shield->lookup($request, new ResponseFactory()->createResponse()));
        }
    }

    #[Test]
    public function an_upstream_that_says_do_not_store_is_obeyed(): void
    {
        $shield = $this->shield();

        $cases = [
            'set-cookie' => $this->response()->withHeader('Set-Cookie', 'sid=abc'),
            'no-store' => $this->response()->withHeader('Cache-Control', 'no-store'),
            'private' => $this->response()->withHeader('Cache-Control', 'private, max-age=60'),
        ];

        foreach ($cases as $label => $response) {
            $request = $this->request(uri: 'http://gw.local/' . $label);
            self::assertSame('BYPASS', $shield->store($request, $response)->getHeaderLine('X-EcoShield-Cache'));
            self::assertNull($shield->lookup($request, new ResponseFactory()->createResponse()));
        }
    }

    #[Test]
    public function a_body_over_the_cap_streams_through_uncached(): void
    {
        // Le plafond est ce qui réconcilie le cache avec la mémoire constante :
        // au-delà, on refuse de matérialiser le corps plutôt que de gagner un hit.
        $shield = $this->shield(maxBodyBytes: 16);

        $request = $this->request();
        $stored = $shield->store($request, $this->response(body: str_repeat('a', 64)));

        self::assertSame('STREAM', $stored->getHeaderLine('X-EcoShield-Cache'));
        self::assertSame(str_repeat('a', 64), (string) $stored->getBody());
        self::assertNull($shield->lookup($request, new ResponseFactory()->createResponse()));
    }

    #[Test]
    public function distinct_targets_never_share_an_entry(): void
    {
        $shield = $this->shield();

        $shield->store($this->request(uri: 'http://gw.local/a'), $this->response(body: '"a"'));
        $shield->store($this->request(uri: 'http://gw.local/b'), $this->response(body: '"b"'));

        $a = $shield->lookup($this->request(uri: 'http://gw.local/a'), new ResponseFactory()->createResponse());
        $b = $shield->lookup($this->request(uri: 'http://gw.local/b'), new ResponseFactory()->createResponse());

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame('"a"', (string) $a->getBody());
        self::assertSame('"b"', (string) $b->getBody());
    }

    #[Test]
    public function the_query_string_is_part_of_the_identity(): void
    {
        $shield = $this->shield();
        $shield->store($this->request(uri: 'http://gw.local/list?page=1'), $this->response(body: '"p1"'));

        self::assertNull($shield->lookup(
            $this->request(uri: 'http://gw.local/list?page=2'),
            new ResponseFactory()->createResponse(),
        ));
    }

    #[Test]
    public function the_cache_key_stays_within_the_psr16_limit(): void
    {
        // PSR-16 plafonne les clés à 64 caractères et le framework applique la
        // limite : un sha256 complet plus un préfixe la dépassait, et toute
        // requête proxyfiée finissait en 500.
        $shield = $this->shield();
        $longPath = 'http://gw.local/' . str_repeat('segment/', 40);

        $stored = $shield->store($this->request(uri: $longPath), $this->response());

        self::assertSame('MISS', $stored->getHeaderLine('X-EcoShield-Cache'));
        self::assertNotNull($shield->lookup($this->request(uri: $longPath), new ResponseFactory()->createResponse()));
    }
}
