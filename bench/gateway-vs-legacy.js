// Protocole de benchmark EcoShield — k6.
//
// Ce qui est comparé n'est PAS "un framework contre un autre" : les deux chemins
// servent la même charge utile, produite par le même code applicatif trivial.
// La seule variable est l'ARCHITECTURE D'EXÉCUTION — framework résident contre
// framework reconstruit à chaque requête — parce que c'est la seule chose que le
// pattern Strangler Fig change réellement.
//
// Quatre scénarios, exécutés en séquence pour que la RAM et le CPU relevés en
// parallèle soient attribuables sans ambiguïté :
//
//   1. legacy_direct  — référence : Nginx + PHP-FPM, sans passerelle
//   2. gateway_proxy  — même route via la passerelle, cache neutralisé
//   3. gateway_shield — même route via la passerelle, mutualisable
//   4. gateway_rescue — route reprise, servie depuis le worker
//
// Usage :
//   docker compose up -d
//   bench/run.sh                 (relève aussi la mémoire — c'est LA métrique FinOps)

import http from 'k6/http';
import { check } from 'k6';
import { Trend } from 'k6/metrics';

const GATEWAY = __ENV.GATEWAY_URL || 'http://localhost:8099';
const LEGACY = __ENV.LEGACY_URL || 'http://localhost:8098';
const VUS = Number(__ENV.VUS || 20);
const DURATION = __ENV.DURATION || '30s';

const tLegacyDirect = new Trend('ec_legacy_direct_ms', true);
const tGatewayProxy = new Trend('ec_gateway_proxy_ms', true);
const tGatewayShield = new Trend('ec_gateway_shield_ms', true);
const tGatewayRescue = new Trend('ec_gateway_rescue_ms', true);

function phase(exec, startTime) {
  return { executor: 'constant-vus', vus: VUS, duration: DURATION, exec, startTime, gracefulStop: '5s' };
}

export const options = {
  scenarios: {
    legacy_direct: phase('legacyDirect', '0s'),
    gateway_proxy: phase('gatewayProxy', '35s'),
    gateway_shield: phase('gatewayShield', '70s'),
    gateway_rescue: phase('gatewayRescue', '105s'),
  },
  // Des seuils qui doivent échouer si la passerelle cesse de tenir sa promesse,
  // pas encadrer confortablement un résultat déjà connu.
  thresholds: {
    http_req_failed: ['rate<0.01'],
    ec_gateway_rescue_ms: ['p(95)<15'],
    ec_gateway_shield_ms: ['p(95)<25'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

// 1. Référence : le monolithe seul, tel qu'il tourne aujourd'hui en production.
export function legacyDirect() {
  const res = http.get(`${LEGACY}/api/products/42`);
  tLegacyDirect.add(res.timings.duration);
  check(res, { 'legacy 200': (r) => r.status === 200 });
}

// 2. La passerelle en pur proxy : le paramètre unique interdit toute mise en
//    cache, ce qui isole le coût du relais lui-même (le legacy redémarre quand
//    même son framework à chaque requête).
export function gatewayProxy() {
  const res = http.get(`${GATEWAY}/api/orders/7?nocache=${__VU}-${__ITER}`);
  tGatewayProxy.add(res.timings.duration);
  check(res, { 'proxy 200': (r) => r.status === 200 });
}

// 3. Le Shield : même route pour tous les VUs, donc mutualisable. Le monolithe
//    n'est plus touché qu'une fois par TTL.
export function gatewayShield() {
  const res = http.get(`${GATEWAY}/api/catalogue`);
  tGatewayShield.add(res.timings.duration);
  check(res, {
    'shield 200': (r) => r.status === 200,
    'shield rend un verdict': (r) => ['HIT', 'MISS', 'BYPASS', 'STREAM'].includes(r.headers['X-Ecoshield-Cache']),
  });
}

// 4. Route reprise : le monolithe n'est jamais contacté.
export function gatewayRescue() {
  const res = http.get(`${GATEWAY}/api/products/42`);
  tGatewayRescue.add(res.timings.duration);
  check(res, {
    'rescue 200': (r) => r.status === 200,
    'servie par la passerelle': (r) => r.json('served_by') === 'ecoshield-gateway',
  });
}
