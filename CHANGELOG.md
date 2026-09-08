# Changelog — EcoShield Gateway

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Versioned against the Waffle Commons release it runs on, but tagged independently —
the gateway is not part of the framework's release wave.

## [0.1.0-beta6] — 2026-09

First release of the POC as a **deployable application**. Earlier iterations shipped only the
reverse-proxy class; this one is the gateway described in the README — something you start with
`docker compose up -d` and put in front of a monolith.

### Added
- **Pillar 1 — Interception (Rescue).** `RescueController` serves rescued routes from the resident
  worker, never touching the monolith. `/__ecoshield/health` reports on the **gateway alone**: a
  probe that fails because the upstream is down would have an orchestrator restart the one component
  still able to serve cached traffic.
- **Pillar 2 — Transparent proxying.** `GatewayController` is a catch-all at `priority: -1000`, so
  every native route — present or future — is preferred without any configuration. Rescuing a route
  means adding one to `RescueController`; the legacy's own routing and the client-facing URLs never
  change. That is the Strangler Fig, made incremental.
- **Pillar 3 — Shield.** `ResponseCache` caches upstream responses in PSR-16 (Redis in compose).
  It refuses far more than it accepts, deliberately: non-GET/HEAD, any request bearing
  `Authorization` or `Cookie`, any non-200, and any response carrying `Set-Cookie` or
  `Cache-Control: no-store`/`private`. Sharing a private response between clients is the classic
  gateway-cache data leak, and it is cheaper to prevent than to detect.
- **The application shell**: FrankenPHP worker entrypoint, kernel factory, YAML configuration,
  OPcache preloading, `.env.example`.
- **`docker compose up -d` brings up the whole demonstration**: the gateway in worker mode, a legacy
  monolith behind Nginx + PHP-FPM that rebuilds its framework on every request, and Redis.
- **`bench/gateway-vs-legacy.js`** — a k6 protocol comparing the three paths (rescued / proxied /
  cached) rather than comparing two frameworks: the only variable it changes is whether the
  framework is resident or rebuilt.

### Notes
- **Constant memory is reconciled with caching by a size cap.** Caching means materialising a body,
  which is exactly what the proxy refuses to do to hold ΔM = 0. Past `shield.max_body_bytes` the
  response streams through uncached. A gateway that cached everything would trade its memory
  guarantee for a hit rate, and lose the only thing this POC sets out to demonstrate.
- **No SSRF guard on the upstream client, by design.** The destination is fixed by the operator; the
  proxy takes only path and query from the inbound message. A guard would reject the internal
  addressing that is a gateway's whole reason to exist.
- **No authentication.** `AnonymousSecurityContext` satisfies the framework's `SecureContainer`
  without pretending to verify anything; edge auth is beta7 (`Roadmap_Beta7` AXE 1).
- Runs on `waffle-commons/*` `0.1.0-beta6`, installed from Packagist.

### Quality gates
`composer mago` zero output · 42 tests, **99.15% statement coverage** · `igor-php` **0 KO**
(10/10 stateless) · `composer validate --strict` clean.

### Not implemented yet
Upstream connection pooling, retry and circuit breaking (`resilience-net`, beta7 `[NET-01]`),
WebSocket upgrade passthrough, and load balancing across several upstreams. The gateway grows to
alpha in beta7 (`[GATE-02]`) and to beta in beta8 (`[GATE-03]`).
