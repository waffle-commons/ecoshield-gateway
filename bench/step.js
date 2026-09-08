// Une marche de l'échelle de concurrence : un seul chemin, une seule
// concurrence, une seule durée. L'orchestration est dans bench/ladder.sh, ce qui
// garde ce fichier lisible et permet de relever la mémoire marche par marche.
import http from 'k6/http';
import { check } from 'k6';

const GATEWAY = __ENV.GATEWAY_URL || 'http://gateway:80';
const LEGACY = __ENV.LEGACY_URL || 'http://legacy-nginx:80';
const PATH = __ENV.SCENARIO || 'rescue';

export const options = {
  vus: Number(__ENV.VUS || 1),
  duration: __ENV.DURATION || '15s',
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  thresholds: { http_req_failed: ['rate<0.01'] },
};

const targets = {
  // Référence : le monolithe seul, joint directement.
  legacy: () => `${LEGACY}/api/products/42`,
  // Route reprise : servie par le worker, le monolithe n'est jamais contacté.
  rescue: () => `${GATEWAY}/api/products/42`,
  // Proxy pur : le paramètre unique interdit la mise en cache.
  proxy: () => `${GATEWAY}/api/orders/7?nocache=${__VU}-${__ITER}`,
  // Shield : même cible pour tous les VUs, donc mutualisable.
  shield: () => `${GATEWAY}/api/catalogue`,
};

export default function () {
  const res = http.get(targets[PATH]());
  check(res, { '200': (r) => r.status === 200 });
}
