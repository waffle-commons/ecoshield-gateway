// Charge d'endurance : un mélange réaliste plutôt qu'une seule route.
//
// Une fuite se cache dans le chemin le moins exercé. Le mélange couvre donc les
// trois piliers — reprise, proxy (cache neutralisé, donc I/O amont à chaque
// itération) et Shield — pour qu'aucun ne reste hors du soak.
import http from 'k6/http';
import { check } from 'k6';

const GATEWAY = __ENV.GATEWAY_URL || 'http://gateway:80';

export const options = {
  vus: Number(__ENV.VUS || 8),
  duration: __ENV.DURATION || '30m',
  thresholds: { http_req_failed: ['rate<0.01'] },
  summaryTrendStats: ['med', 'p(95)', 'p(99)', 'max'],
};

export default function () {
  const rescue = http.get(`${GATEWAY}/api/products/42`);
  check(rescue, { 'rescue 200': (r) => r.status === 200 });

  const proxied = http.get(`${GATEWAY}/api/orders/7?nocache=${__VU}-${__ITER}`);
  check(proxied, { 'proxy 200': (r) => r.status === 200 });

  const shielded = http.get(`${GATEWAY}/api/catalogue`);
  check(shielded, { 'shield 200': (r) => r.status === 200 });
}
