<?php

declare(strict_types=1);

namespace Waffle\Commons\EcoshieldGateway;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Waffle\Commons\EcoshieldGateway\Exception\GatewayException;
use Waffle\Commons\EcoshieldGateway\Http\HopByHopHeaders;

/**
 * Streaming reverse proxy (RFC-013 — EcoShield POC, `[GATE-01]`).
 *
 * ## Why this component exists
 *
 * EcoShield is the dogfooding project: a real, useful piece of infrastructure
 * built **exclusively on public Waffle APIs**. Anything the gateway cannot
 * express without reaching into a component's internals is a framework design
 * bug to fix upstream, not something to work around here. This POC found none —
 * PSR-7/17/18 plus the contracts perimeter were sufficient.
 *
 * ## Bounded memory
 *
 * The soak target is $\Delta M = 0$: memory must be constant regardless of
 * payload size, so neither direction may be buffered whole. That property comes
 * from `waffle-commons/http-client`, which already streams both directions in
 * 8 KiB chunks (`CURLOPT_WRITEFUNCTION` on the way down, `CURLOPT_READFUNCTION`
 * with `CURLOPT_UPLOAD` on the way up). This class therefore hands the upstream
 * request the *inbound body stream itself* rather than a copy: a 2 GiB upload
 * traverses the proxy in 8 KiB steps and never lands in worker memory.
 *
 * The one rule that keeps it true: **never call `(string) $body`** on either
 * message. A single string cast silently converts this class from a streaming
 * proxy into a buffering one, and nothing in the type system will complain.
 *
 * ## Statelessness
 *
 * `final readonly`, no instance state beyond injected collaborators, nothing
 * retained between calls — the worker loop sees an identical object on every
 * iteration (`wfl igor` 0 KO).
 */
final readonly class ProxyController
{
    /**
     * Headers a proxy must control itself rather than inherit from the client.
     *
     * `Host` is recomputed for the upstream authority; the `X-Forwarded-*`
     * family is *appended to*, never trusted as received — a client that sends
     * its own `X-Forwarded-For` is attempting to forge its origin, so the real
     * peer is always appended last where a correct consumer reads it.
     */
    private const array CLIENT_CONTROLLED = [
        'host',
        'x-forwarded-for',
        'x-forwarded-proto',
        'x-forwarded-host',
        // The rest of the forwarding family. Dropping only the three headers
        // this class rewrites would let a client keep authorship of the others:
        // `Forwarded` is the RFC 7239 standard form of exactly the information
        // X-Forwarded-* carries, and an upstream that reads it (or X-Real-IP,
        // which many stacks trust for rate-limiting and audit) would believe a
        // value this hop never observed.
        'forwarded',
        'x-real-ip',
        'x-forwarded-port',
        'x-forwarded-prefix',
    ];

    /** Bounded so a deeply nested percent-encoding cannot spin the decoder. */
    private const int MAX_DECODE_PASSES = 4;

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private UriInterface $upstream,
    ) {}

    /**
     * Proxies one request upstream and returns the upstream response.
     *
     * @throws GatewayException When the inbound message is unsafe to forward, or
     *                          the upstream cannot be reached.
     */
    public function proxy(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertUnambiguousFraming($request);

        $upstreamRequest = $this->requestFactory->createRequest(
            $request->getMethod(),
            $this->rewriteTarget($request->getUri()),
        );

        foreach (HopByHopHeaders::strip($request) as $name => $values) {
            $header = (string) $name;
            if (in_array(mb_strtolower($header), self::CLIENT_CONTROLLED, true)) {
                continue;
            }

            $upstreamRequest = $upstreamRequest->withHeader($header, $values);
        }

        $upstreamRequest = $this->applyForwardedHeaders($upstreamRequest, $request);

        // The inbound stream is passed through by reference, NOT copied — this is
        // what makes memory constant with respect to body size.
        $upstreamRequest = $upstreamRequest->withBody($request->getBody());

        try {
            $response = $this->client->sendRequest($upstreamRequest);
        } catch (ClientExceptionInterface $failure) {
            throw GatewayException::upstreamUnreachable($failure);
        }

        return $this->sanitiseResponse($response);
    }

    /**
     * Rejects messages whose framing is ambiguous.
     *
     * A request carrying both `Content-Length` and `Transfer-Encoding` can be
     * read two different ways; when the proxy and the upstream choose
     * differently, one request becomes two — request smuggling. RFC 9112 §6.1
     * permits stripping `Content-Length`, but a gateway cannot know which
     * interpretation the client intended, so it refuses rather than guesses.
     *
     * Multiple conflicting `Content-Length` values are rejected on the same
     * grounds.
     *
     * @throws GatewayException
     */
    private function assertUnambiguousFraming(ServerRequestInterface $request): void
    {
        $hasLength = $request->hasHeader('Content-Length');
        $hasChunked = $request->hasHeader('Transfer-Encoding');

        if ($hasLength && $hasChunked) {
            throw GatewayException::ambiguousFraming();
        }

        if ($hasLength && count(array_unique($request->getHeader('Content-Length'))) > 1) {
            throw GatewayException::ambiguousFraming();
        }
    }

    /**
     * Maps the inbound path onto the upstream authority.
     *
     * Scheme, host and port always come from the configured upstream: the
     * client controls the path and query, never the destination. The upstream's
     * own base path is preserved as a prefix so the gateway can sit in front of
     * a sub-path deployment.
     *
     * @throws GatewayException
     */
    private function rewriteTarget(UriInterface $inbound): UriInterface
    {
        $path = $inbound->getPath();

        $this->assertTraversalFree($path);

        $base = mb_rtrim($this->upstream->getPath(), '/');

        return $this->upstream
            ->withPath($base . $path)
            ->withQuery($inbound->getQuery())
            ->withFragment('');
    }

    /**
     * Rejects any target that could escape the upstream base path.
     *
     * The check runs on a fully decoded copy while the ORIGINAL, still-encoded
     * path is what gets forwarded — the upstream must receive what the client
     * actually sent, so decoding here is for adjudication only.
     *
     * Decoding is repeated until it reaches a fixed point because a single pass
     * is not enough: `%2e%2e` survives a literal `..` test, and `%252e%252e`
     * survives one decode and becomes `%2e%2e`. The loop is bounded so a
     * pathological input cannot spin.
     *
     * Segments are compared exactly rather than with a substring test: `..` as a
     * substring would reject a legitimate file named `notes..bak`, while `..` as
     * a whole segment is the only form that actually climbs a level.
     *
     * @throws GatewayException
     */
    private function assertTraversalFree(string $path): void
    {
        $decoded = $path;
        $settled = false;

        for ($pass = 0; $pass < self::MAX_DECODE_PASSES; $pass++) {
            $next = rawurldecode($decoded);

            if ($next === $decoded) {
                $settled = true;
                break;
            }

            $decoded = $next;
        }

        // Reaching the bound while the value is STILL changing means the target
        // is more deeply nested than the decoder is willing to follow. Checking
        // the partially-decoded string here would be worse than useless: it
        // would report "no traversal" about a value nobody has fully resolved,
        // and an upstream that decodes one level further than we did would then
        // see the `..` we approved. Nothing legitimate needs this many passes,
        // so refuse instead of guessing.
        if (!$settled) {
            throw GatewayException::invalidTarget('the target is encoded too deeply to validate.');
        }

        // A backslash is a path separator on the upstream's filesystem for some
        // servers, so `..\` is traversal that a `/`-only split would miss.
        $normalised = str_replace('\\', '/', $decoded);

        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '..') {
                throw GatewayException::invalidTarget('path traversal is not permitted.');
            }
        }

        // A leading `//` makes the target protocol-relative, which is how a
        // client redirects the proxy at a host of its choosing.
        if (str_starts_with($normalised, '//')) {
            throw GatewayException::invalidTarget('protocol-relative targets are not permitted.');
        }

        if (str_contains($decoded, "\0")) {
            throw GatewayException::invalidTarget('the target contains a null byte.');
        }
    }

    /**
     * Sets `Host` for the upstream authority and appends the forwarding trail.
     *
     * Appending (rather than replacing) preserves a legitimate chain of proxies
     * while ensuring the value this hop observed is the last element — the only
     * element downstream code can trust.
     */
    private function applyForwardedHeaders(
        \Psr\Http\Message\RequestInterface $upstreamRequest,
        ServerRequestInterface $original,
    ): \Psr\Http\Message\RequestInterface {
        $forwardedFor = $original->getHeader('X-Forwarded-For');
        // Narrowed inline rather than via an intermediate variable: `$_SERVER` is
        // `array<string, mixed>`, so binding the value first would introduce a
        // `mixed` local for no benefit.
        $serverParams = $original->getServerParams();
        if (
            array_key_exists('REMOTE_ADDR', $serverParams)
            && is_string($serverParams['REMOTE_ADDR'])
            && $serverParams['REMOTE_ADDR'] !== ''
        ) {
            $forwardedFor[] = $serverParams['REMOTE_ADDR'];
        }

        $upstreamRequest = $upstreamRequest->withHeader('Host', $this->upstream->getAuthority());

        if ($forwardedFor !== []) {
            $upstreamRequest = $upstreamRequest->withHeader('X-Forwarded-For', implode(', ', $forwardedFor));
        }

        $scheme = $original->getUri()->getScheme();
        if ($scheme !== '') {
            $upstreamRequest = $upstreamRequest->withHeader('X-Forwarded-Proto', $scheme);
        }

        $host = $original->getUri()->getHost();
        if ($host !== '') {
            $upstreamRequest = $upstreamRequest->withHeader('X-Forwarded-Host', $host);
        }

        return $upstreamRequest;
    }

    /**
     * Strips hop-by-hop headers from the upstream response before it is emitted.
     *
     * The response body stream is returned untouched: the client already filled
     * it in 8 KiB increments, and the emitter drains it the same way, so a large
     * download never materialises in worker memory.
     */
    private function sanitiseResponse(ResponseInterface $response): ResponseInterface
    {
        $kept = HopByHopHeaders::strip($response);

        $sanitised = $response;
        foreach (array_keys($response->getHeaders()) as $name) {
            $sanitised = $sanitised->withoutHeader((string) $name);
        }

        foreach ($kept as $name => $values) {
            $sanitised = $sanitised->withHeader((string) $name, $values);
        }

        return $sanitised;
    }
}
