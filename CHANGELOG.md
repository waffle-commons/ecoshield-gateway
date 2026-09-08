# Changelog — waffle-commons/ecoshield-gateway

All notable changes to this package are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Versioned against the Waffle Commons release it is built and verified on, but tagged
independently — the gateway is not part of the framework's release wave.

## [0.1.0-beta6] — 2026-09

First published release. Built as the `Roadmap_Beta6` AXE 4 deliverable (`[GATE-01]`): the
dogfooding proof that a genuinely useful piece of infrastructure can be written against the
framework's public surface alone.

### Added
- **`ProxyController`** — a streaming reverse proxy over PSR-7/17/18.
  - Hop-by-hop headers stripped in both directions: the RFC's fixed set **and** every field named
    by the message's own `Connection` header, which is the half most implementations miss.
  - `Host` recomputed for the upstream authority; the client never dictates the destination.
  - `X-Forwarded-For` / `-Proto` / `-Host` appended, never trusted as received — a client sending
    its own forwarding chain is forging its origin, so the observed peer is appended last.
  - Ambiguous framing rejected with 400: a message carrying both `Content-Length` and
    `Transfer-Encoding`, or two different `Content-Length` values, can be read two ways, and one
    request becomes two when proxy and upstream disagree.
  - Upstream failures mapped to 502 without naming internal topology.
- **Constant memory by construction.** The inbound body stream is handed to the upstream request
  itself rather than copied, so payload size never enters worker memory. Pinned by a test asserting
  the *same stream instance* reaches the upstream — a single `(string)` cast would silently turn
  this into a buffering proxy with nothing in the type system objecting.
- `HopByHopHeaders` and `GatewayException` supporting types.

### Notes
- **Runtime dependencies are PSR interfaces only** — not even `waffle-commons/contracts`. The
  framework packages are dev-only (`waffle-commons/http` backs the tests) or suggested
  (`waffle-commons/http-client`, for its 8 KiB streaming in both directions).
- Verified against `waffle-commons/*` `0.1.0-beta6` installed from Packagist.
- Gates: `composer mago` zero output · 21 tests, 100 % statement coverage · `igor-php` **0 KO**
  (3/3 stateless, clean under both the pinned 0.7.0 and 0.8.9).

### Not implemented yet
Upstream connection pooling, retry and circuit breaking (that is `resilience-net`, beta7
`[NET-01]`), response caching, WebSocket upgrade passthrough, and load balancing across several
upstreams. The gateway grows to alpha in beta7 (`[GATE-02]`) and to beta in beta8 (`[GATE-03]`).
