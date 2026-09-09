// Test de rupture — où la passerelle cesse-t-elle de tenir ?
//
// L'échelle de concurrence mesure des points stables ; celui-ci cherche le
// GENOU : le moment où ajouter des clients n'ajoute plus de débit, seulement de
// la latence. C'est le chiffre qu'un exploitant doit connaître pour dimensionner
// `WORKER_COUNT`, et sans lui toute annonce de latence est une moyenne prise à
// une charge arbitraire.
//
//   docker run --rm --network ecoshield-gateway_default -e SCENARIO=rescue \
//     -v "$PWD/bench:/bench" grafana/k6:latest run /bench/stress.js
import http from 'k6/http';
import { check } from 'k6';

const GATEWAY = __ENV.GATEWAY_URL || 'http://gateway:80';
const LEGACY = __ENV.LEGACY_URL || 'http://legacy-nginx:80';
const PATH = __ENV.SCENARIO || 'rescue';
const PEAK = Number(__ENV.PEAK_VUS || 200);
const STAGE = __ENV.STAGE || '30s';

export const options = {
  scenarios: {
    ramp: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: STAGE, target: Math.round(PEAK * 0.1) },
        { duration: STAGE, target: Math.round(PEAK * 0.25) },
        { duration: STAGE, target: Math.round(PEAK * 0.5) },
        { duration: STAGE, target: PEAK },
        // Redescente : une passerelle saine RÉCUPÈRE. Si la latence reste haute
        // après la pointe, c'est une file qui ne se vide pas — un incident, pas
        // une saturation passagère.
        { duration: STAGE, target: 1 },
      ],
      gracefulRampDown: '10s',
    },
  },
  summaryTrendStats: ['med', 'p(95)', 'p(99)', 'max'],
  // Aucun seuil : un test de rupture cherche la limite, il ne la décrète pas.
  // Le seul échec qui compte ici est une erreur non liée à la charge.
  thresholds: { http_req_failed: ['rate<0.05'] },
};

const targets = {
  legacy: () => `${LEGACY}/api/products/42`,
  rescue: () => `${GATEWAY}/api/products/42`,
  proxy: () => `${GATEWAY}/api/orders/7?nocache=${__VU}-${__ITER}`,
  shield: () => `${GATEWAY}/api/catalogue`,
};

export default function () {
  const res = http.get(targets[PATH]());
  check(res, { '200': (r) => r.status === 200 });
}
