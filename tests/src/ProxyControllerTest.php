<?php

declare(strict_types=1);

namespace WaffleTests\Commons\EcoshieldGateway;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Waffle\Commons\EcoshieldGateway\Exception\GatewayException;
use Waffle\Commons\EcoshieldGateway\Http\HopByHopHeaders;
use Waffle\Commons\EcoshieldGateway\ProxyController;
use Waffle\Commons\Http\Factory\RequestFactory;
use Waffle\Commons\Http\Factory\StreamFactory;
use Waffle\Commons\Http\Factory\UriFactory;
use Waffle\Commons\Http\Response;
use Waffle\Commons\Http\ServerRequest;
use WaffleTests\Commons\EcoshieldGateway\Helper\RecordingClient;
use WaffleTests\Commons\EcoshieldGateway\Helper\ThrowingClient;

#[CoversClass(ProxyController::class)]
#[CoversClass(HopByHopHeaders::class)]
#[CoversClass(GatewayException::class)]
final class ProxyControllerTest extends TestCase
{
    private function makeController(RecordingClient|ThrowingClient $client): ProxyController
    {
        return new ProxyController(
            client: $client,
            requestFactory: new RequestFactory(),
            upstream: new UriFactory()->createUri('http://upstream.internal:8080'),
        );
    }

    private function makeRequest(
        string $method = 'GET',
        string $uri = 'http://gateway.test/orders/42?q=1',
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: new UriFactory()->createUri($uri),
            body: new StreamFactory()->createStream(''),
            serverParams: ['REMOTE_ADDR' => '203.0.113.7'],
        );
    }

    public function testTargetsTheUpstreamAuthorityAndPreservesPathAndQuery(): void
    {
        $client = new RecordingClient(new Response(statusCode: 200));
        $this->makeController($client)->proxy($this->makeRequest());

        $sent = $client->lastRequest();
        self::assertInstanceOf(RequestInterface::class, $sent);
        self::assertSame('upstream.internal:8080', $sent->getUri()->getAuthority());
        self::assertSame('/orders/42', $sent->getUri()->getPath());
        self::assertSame('q=1', $sent->getUri()->getQuery());
        // The client never dictates the destination — only path and query.
        self::assertSame('http', $sent->getUri()->getScheme());
    }

    public function testHostIsRewrittenToTheUpstreamNotInheritedFromTheClient(): void
    {
        $client = new RecordingClient(new Response(statusCode: 200));
        $request = $this->makeRequest()->withHeader('Host', 'gateway.test');

        $this->makeController($client)->proxy($request);

        self::assertSame('upstream.internal:8080', $client->lastRequest()?->getHeaderLine('Host'));
    }

    public function testAppendsTheRealPeerToAForgedForwardedForChain(): void
    {
        // A client may send its own X-Forwarded-For to fake its origin. The real
        // peer must be APPENDED last, where a correct consumer reads it.
        $client = new RecordingClient(new Response(statusCode: 200));
        $request = $this->makeRequest()->withHeader('X-Forwarded-For', '10.0.0.1');

        $this->makeController($client)->proxy($request);

        self::assertSame('10.0.0.1, 203.0.113.7', $client->lastRequest()?->getHeaderLine('X-Forwarded-For'));
        self::assertSame('http', $client->lastRequest()?->getHeaderLine('X-Forwarded-Proto'));
        self::assertSame('gateway.test', $client->lastRequest()?->getHeaderLine('X-Forwarded-Host'));
    }

    public function testStripsHopByHopHeadersFromTheForwardedRequest(): void
    {
        $client = new RecordingClient(new Response(statusCode: 200));
        $request = $this
            ->makeRequest()
            ->withHeader('Keep-Alive', 'timeout=5')
            ->withHeader('Proxy-Authorization', 'Basic zzz')
            ->withHeader('Accept', 'application/json');

        $this->makeController($client)->proxy($request);

        $sent = $client->lastRequest();
        self::assertFalse($sent?->hasHeader('Keep-Alive'));
        self::assertFalse($sent?->hasHeader('Proxy-Authorization'));
        self::assertSame('application/json', $sent?->getHeaderLine('Accept'));
    }

    public function testStripsHeadersNamedByTheConnectionHeader(): void
    {
        // RFC 9110 §7.6.1: Connection lists ADDITIONAL hop-by-hop fields. A proxy
        // that forwards them leaks headers the client expected to die at the hop.
        $client = new RecordingClient(new Response(statusCode: 200));
        $request = $this
            ->makeRequest()
            ->withHeader('Connection', 'X-Internal-Auth, X-Trace')
            ->withHeader('X-Internal-Auth', 'secret')
            ->withHeader('X-Trace', 'abc')
            ->withHeader('X-Keep', 'yes');

        $this->makeController($client)->proxy($request);

        $sent = $client->lastRequest();
        self::assertFalse($sent?->hasHeader('X-Internal-Auth'));
        self::assertFalse($sent?->hasHeader('X-Trace'));
        self::assertFalse($sent?->hasHeader('Connection'));
        self::assertSame('yes', $sent?->getHeaderLine('X-Keep'));
    }

    public function testRejectsRequestSmugglingViaConflictingFraming(): void
    {
        $client = new RecordingClient(new Response(statusCode: 200));
        $request = $this
            ->makeRequest('POST')
            ->withHeader('Content-Length', '5')
            ->withHeader('Transfer-Encoding', 'chunked');

        $this->expectException(GatewayException::class);
        $this->expectExceptionCode(400);

        $this->makeController($client)->proxy($request);
    }

    public function testRejectsConflictingContentLengthValues(): void
    {
        $client = new RecordingClient(new Response(statusCode: 200));
        $request = $this->makeRequest('POST')->withHeader('Content-Length', ['5', '9']);

        $this->expectException(GatewayException::class);
        $this->expectExceptionCode(400);

        $this->makeController($client)->proxy($request);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalProvider(): iterable
    {
        yield 'literal' => ['http://gateway.test/a/../../etc/passwd'];
        yield 'percent-encoded' => ['http://gateway.test/a/%2e%2e/%2e%2e/etc/passwd'];
        yield 'percent-encoded uppercase' => ['http://gateway.test/a/%2E%2E/etc/passwd'];
        yield 'double-encoded' => ['http://gateway.test/a/%252e%252e/etc/passwd'];
        yield 'backslash separator' => ['http://gateway.test/a/..\\..\\etc'];
    }

    #[DataProvider('traversalProvider')]
    public function testRejectsEveryEncodingOfTraversal(string $uri): void
    {
        // A literal `..` test is not enough: %2e%2e passes it, and %252e%252e
        // passes a single decode. The guard decodes to a fixed point first.
        $this->expectException(GatewayException::class);
        $this->expectExceptionCode(400);

        $this->makeController(new RecordingClient(new Response(statusCode: 200)))->proxy($this->makeRequest(uri: $uri));
    }

    public function testRejectsANullByteInTheTarget(): void
    {
        // %00 truncates the path for some upstream filesystems, so it must not
        // survive the hop even though it is neither `..` nor `//`.
        $this->expectException(GatewayException::class);
        $this->expectExceptionCode(400);

        $this->makeController(new RecordingClient(new Response(statusCode: 200)))->proxy($this->makeRequest(
            uri: 'http://gateway.test/files/report%00.pdf',
        ));
    }

    public function testRejectsATargetEncodedMoreDeeplyThanTheDecoderFollows(): void
    {
        // The decoder is bounded, so a value still changing when the bound is
        // reached has NOT been fully resolved. Adjudicating on the partial
        // result would approve a `..` that an upstream decoding one level
        // further would see, so the guard refuses instead.
        $this->expectException(GatewayException::class);
        $this->expectExceptionCode(400);

        $this->makeController(new RecordingClient(new Response(statusCode: 200)))->proxy($this->makeRequest(
            uri: 'http://gateway.test/a/%252525252e%252525252e/etc',
        ));
    }

    public function testAllowsALegitimateFilenameContainingDots(): void
    {
        // `..` as a SUBSTRING is harmless — only a whole `..` segment climbs.
        $client = new RecordingClient(new Response(statusCode: 200));
        $this->makeController($client)->proxy($this->makeRequest(uri: 'http://gateway.test/files/notes..bak'));

        self::assertSame('/files/notes..bak', $client->lastRequest()?->getUri()->getPath());
    }

    public function testRejectsAProtocolRelativeTarget(): void
    {
        // A leading `//` is how a client redirects the proxy at a host it chose.
        $this->expectException(GatewayException::class);
        $this->expectExceptionCode(400);

        $this->makeController(new RecordingClient(new Response(statusCode: 200)))->proxy($this->makeRequest(
            uri: 'http://gateway.test//evil.example.com/steal',
        ));
    }

    public function testStripsTheWholeForwardingHeaderFamilyFromTheClient(): void
    {
        // Rewriting only X-Forwarded-{For,Proto,Host} would leave the client
        // authoring Forwarded / X-Real-IP, which upstreams also trust.
        $client = new RecordingClient(new Response(statusCode: 200));
        $request = $this
            ->makeRequest()
            ->withHeader('Forwarded', 'for=1.2.3.4;proto=https')
            ->withHeader('X-Real-IP', '1.2.3.4')
            ->withHeader('X-Forwarded-Port', '443')
            ->withHeader('X-Forwarded-Prefix', '/admin');

        $this->makeController($client)->proxy($request);

        $sent = $client->lastRequest();
        self::assertFalse($sent?->hasHeader('Forwarded'));
        self::assertFalse($sent?->hasHeader('X-Real-IP'));
        self::assertFalse($sent?->hasHeader('X-Forwarded-Port'));
        self::assertFalse($sent?->hasHeader('X-Forwarded-Prefix'));
    }

    public function testUpstreamFailureBecomesABadGatewayWithoutLeakingTopology(): void
    {
        $controller = $this->makeController(new ThrowingClient());

        try {
            $controller->proxy($this->makeRequest());
            self::fail('Expected a GatewayException.');
        } catch (GatewayException $e) {
            self::assertSame(502, $e->getCode());
            // The client-facing message must not name the upstream host.
            self::assertStringNotContainsString('upstream.internal', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
    }

    public function testStripsHopByHopHeadersFromTheResponse(): void
    {
        $upstream = new Response(statusCode: 200)
            ->withHeader('Transfer-Encoding', 'chunked')
            ->withHeader('Connection', 'X-Upstream-Only')
            ->withHeader('X-Upstream-Only', 'leak')
            ->withHeader('Content-Type', 'application/json');

        $response = $this->makeController(new RecordingClient($upstream))->proxy($this->makeRequest());

        self::assertFalse($response->hasHeader('Transfer-Encoding'));
        self::assertFalse($response->hasHeader('Connection'));
        self::assertFalse($response->hasHeader('X-Upstream-Only'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPassesTheInboundBodyStreamThroughByReference(): void
    {
        // THE bounded-memory guarantee. The upstream request must carry the SAME
        // stream instance as the inbound request: any copy (notably a `(string)`
        // cast) would materialise the whole body in worker memory and silently
        // turn this into a buffering proxy.
        $client = new RecordingClient(new Response(statusCode: 200));
        $body = new StreamFactory()->createStream('payload');
        $request = $this->makeRequest('POST')->withBody($body);

        $this->makeController($client)->proxy($request);

        self::assertSame($body, $client->lastRequest()?->getBody());
    }

    public function testForwardsTheMethodUnchanged(): void
    {
        $client = new RecordingClient(new Response(statusCode: 204));
        $this->makeController($client)->proxy($this->makeRequest('DELETE'));

        self::assertSame('DELETE', $client->lastRequest()?->getMethod());
    }
}
