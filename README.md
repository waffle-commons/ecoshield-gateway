# waffle-commons/ecoshield-gateway

> **Release:** `0.1.0-beta6` &nbsp;|&nbsp; **Status: proof of concept — published, but not part of the framework release wave.**

A streaming reverse proxy built **exclusively on public Waffle APIs**.

EcoShield is the dogfooding project (`Roadmap_Beta6` AXE 4, `[GATE-01]`): if a genuinely useful
piece of infrastructure cannot be written against the framework's public surface, that is a
framework design bug to fix upstream — not something to work around inside the gateway. This POC
is the first evidence for that claim.

## Installation

```bash
composer require waffle-commons/ecoshield-gateway
```

Requires PHP 8.5+. At runtime the gateway needs nothing but PSR interfaces — you supply the PSR-18
client and PSR-17 request factory. Pair it with
[`waffle-commons/http-client`](https://packagist.org/packages/waffle-commons/http-client) to get the
constant-memory behaviour described below; any conforming PSR-18 client will work, but only a
streaming one preserves $\Delta M = 0$.

## What it does

A catch-all `ProxyController` forwards a PSR-7 request to a configured upstream and returns the
upstream response, while doing the things a proxy is obliged to do and is commonly seen not to:

- **Hop-by-hop headers are stripped** in both directions — the RFC's fixed set *and* every field
  named in the message's own `Connection` header, which is the half that is usually missed.
- **`Host` is recomputed** for the upstream authority; the client never dictates the destination.
- **`X-Forwarded-For` / `-Proto` / `-Host` are appended, never trusted.** A client that sends its
  own forwarding chain is trying to forge its origin; the observed peer is appended last, where a
  correct consumer reads it.
- **Ambiguous framing is rejected.** A request carrying both `Content-Length` and
  `Transfer-Encoding` — or two different `Content-Length` values — can be read two ways, and when
  the proxy and upstream read it differently one request becomes two. The gateway refuses rather
  than guessing (400).
- **Upstream failures become 502** without naming the internal topology in the client-facing
  message.

## Bounded memory

The soak target is $\Delta M = 0$: constant memory regardless of payload size. Neither direction is
buffered whole — the gateway hands the upstream request the *inbound body stream itself*, and
`waffle-commons/http-client` already moves both directions in 8 KiB chunks (`CURLOPT_WRITEFUNCTION`
downstream, `CURLOPT_READFUNCTION` with `CURLOPT_UPLOAD` upstream). A 2 GiB upload therefore
traverses the proxy in 8 KiB steps and never lands in worker memory.

The invariant is one line wide: **never `(string)` a body.** A single cast silently turns this into
a buffering proxy and nothing in the type system objects — so it is asserted by a test that pins the
upstream request to the *same stream instance* as the inbound one.

## Usage

```php
use Waffle\Commons\EcoshieldGateway\ProxyController;

$gateway = new ProxyController(
    client: $container->get(ClientInterface::class),          // PSR-18
    requestFactory: $container->get(RequestFactoryInterface::class), // PSR-17
    upstream: $uriFactory->createUri('http://origin.internal:8080'),
);

$response = $gateway->proxy($request);
```

Wire it behind a catch-all route with the lowest priority so every unmatched path is proxied.

## Dependency perimeter

**PSR interfaces only.** `src/` imports `Psr\Http\Client`, `Psr\Http\Message` and nothing else from
outside its own namespace — not even `waffle-commons/contracts`. That is the strongest form of the
claim this POC was built to test: a genuinely useful piece of infrastructure, written against the
framework's public surface, needed no component internals *and* no framework package at runtime.

`waffle-commons/http` is a dev dependency only (its PSR-7/17 implementations back the test suite),
and `waffle-commons/http-client` is a `suggest` — recommended for its 8 KiB streaming, not required
to compile.

## Status and scope

This is a **POC**, deliberately outside the framework's release wave allow-list: it lives in its own
repository and is tagged on its own, so a gateway release never gates a framework release. It tracks
the framework version it was built and verified against — `0.1.0-beta6`, installed from Packagist.
Per `Roadmap_Beta6` it grows to alpha in beta7 (`[GATE-02]`) and to beta in beta8 (`[GATE-03]`),
soaking on RC1.

Not yet implemented, and honest about it: connection pooling to the upstream, retry/circuit-breaking
(that is `resilience-net`, beta7 `NET-01`), response caching, WebSocket upgrade passthrough, and
load balancing across several upstreams.

## Quality gates

`composer mago` (zero output) · `composer tests` (21 tests, **100 % statement coverage**) ·
`vendor/bin/igor-php .` (**0 KO** — 3/3 stateless, worker-mode compatible).

## License

MIT — see [`LICENSE`](./LICENSE).
