// Protocole de benchmark EcoShield — k6.
//
// Ce que ce scénario compare n'est PAS "un framework contre un autre" : les deux
// chemins servent la même charge utile. Il compare un framework RÉSIDENT à un
// framework RECONSTRUIT à chaque requête, ce qui est la seule variable que le
// pattern Strangler Fig change réellement.
//
//   scenario "rescue" : route reprise par la passerelle    -> jamais de legacy
//   scenario "proxy"  : route non reprise, passée au legacy -> bootstrap complet
//   scenario "shield" : route proxyfiée mais mise en cache  -> legacy touché une fois
//
// Usage :
//   docker compose up -d
//   k6 run bench/gateway-vs-legacy.js
//
// Relever la RAM en parallèle (c'est la métrique FinOps, pas la latence) :
//   docker stats --no-stream ecoshield-gateway ecoshield-legacy-fpm

import http from 'k6/http';
import { check } from 'k6';
import { Trend } from 'k6/metrics';

const GATEWAY = __ENV.GATEWAY_URL || 'http://localhost:8080';

const rescueLatency = new Trend('ecoshield_rescue_ms', true);
const proxyLatency = new Trend('ecoshield_proxy_ms', true);
const shieldLatency = new Trend('ecoshield_shield_ms', true);

export const options = {
  scenarios: {
    rescue: { executor: 'constant-vus', vus: 20, duration: '30s', exec: 'rescue', tags: { path: 'rescue' } },
    proxy: { executor: 'constant-vus', vus: 20, duration: '30s', exec: 'proxy', startTime: '30s', tags: { path: 'proxy' } },
    shield: { executor: 'constant-vus', vus: 20, duration: '30s', exec: 'shield', startTime: '60s', tags: { path: 'shield' } },
  },
  // Seuils délibérément prudents : ils doivent échouer si la passerelle cesse de
  // tenir sa promesse, pas encadrer un résultat déjà connu.
  thresholds: {
    'http_req_failed': ['rate<0.01'],
    'ecoshield_rescue_ms': ['p(95)<10'],
  },
};

// Route reprise : servie depuis le worker, le monolithe n'est jamais contacté.
export function rescue() {
  const res = http.get(`${GATEWAY}/api/products/42`);
  rescueLatency.add(res.timings.duration);
  check(res, {
    'rescue 200': (r) => r.status === 200,
    'servie par la passerelle': (r) => r.json('served_by') === 'ecoshield-gateway',
  });
}

// Route non reprise : chaque requête paie un bootstrap complet du legacy.
// Le paramètre unique empêche toute mise en cache, pour isoler le coût du proxy.
export function proxy() {
  const res = http.get(`${GATEWAY}/legacy/report?nocache=${__VU}-${__ITER}`);
  proxyLatency.add(res.timings.duration);
  check(res, {
    'proxy 200': (r) => r.status === 200,
    'servie par le legacy': (r) => r.json('served_by') === 'legacy-monolith',
  });
}

// Route proxyfiée ET mutualisable : le legacy est touché une fois par TTL.
export function shield() {
  const res = http.get(`${GATEWAY}/legacy/catalogue`);
  shieldLatency.add(res.timings.duration);
  check(res, {
    'shield 200': (r) => r.status === 200,
    'cache renseigne son verdict': (r) => ['HIT', 'MISS', 'BYPASS', 'STREAM'].includes(r.headers['X-Ecoshield-Cache']),
  });
}
