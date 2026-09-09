// bench/k6/scenarios/ladder.js — échelle de débit à modèle OUVERT.
//
// Pendant natif du `bench/ladder.sh` historique, avec deux différences qui
// changent ce que le résultat vaut :
//
//  1. **Modèle ouvert** (`constant-arrival-rate`) et non fermé (`constant-vus`).
//     Un modèle fermé réduit le débit dès que la latence monte : un sujet qui
//     ralentit reçoit moins de charge et paraît sain. Le modèle ouvert maintient
//     le débit demandé et laisse la latence dire la vérité. C'est le choix de
//     BENCH-02 côté écosystème.
//  2. **`dropped_iterations` est relevé par marche.** C'est LE garde-fou de ce
//     scénario, et la raison pour laquelle une échelle mesurée sur cette machine
//     peut être publiée sans mentir — voir ci-dessous.
//
// ## Le garde-fou : quand le générateur devient le sujet
//
// k6 tourne sur l'hôte, qui partage ses cœurs physiques avec la VM Docker. Tant
// que le générateur a de la marge, l'échelle décrit la passerelle. Passé un
// certain débit, le générateur lui-même devient le facteur limitant, et les
// chiffres cessent de décrire quoi que ce soit d'utile.
//
// k6 le dit lui-même : lorsqu'un exécuteur à débit d'arrivée constant ne
// parvient pas à émettre à la cadence demandée — VUs tous occupés, ou générateur
// à court de CPU — il incrémente `dropped_iterations`. Une marche dont ce
// compteur est non nul N'EST PAS UNE MESURE DE CAPACITÉ : le débit visé n'a pas
// été atteint, et la latence observée est celle d'une charge plus faible que
// celle annoncée.
//
// Le rapport applique cette règle mécaniquement plutôt qu'au jugé
// (`perf-report.py`) : marche marquée NON PUBLIABLE dès qu'une itération a été
// abandonnée. C'est ce qui remplace, sur une seule machine, la séparation
// physique du générateur et du sujet.
//
//   RATES=50,100,200,400,800 WORKLOAD=rescue bench/scripts/perf-run.sh ladder
import { workloads, TREND_STATS, makeHandleSummary, durationToSeconds } from '../lib/common.js';

const WORKLOAD = __ENV.WORKLOAD || 'rescue';
if (!Object.prototype.hasOwnProperty.call(workloads, WORKLOAD)) {
  throw new Error(`WORKLOAD inconnu "${WORKLOAD}" — attendu : rescue|shield|proxy|legacy`);
}

const RATES = (__ENV.RATES || '50,100,200,400,800')
  .split(',')
  .map((r) => parseInt(r.trim(), 10))
  .filter((r) => r > 0);

const STEP_DURATION = __ENV.STEP_DURATION || '2m';
const STEP_SECONDS = durationToSeconds(STEP_DURATION);

const scenarios = {};
const thresholds = {};

RATES.forEach((rate, i) => {
  const name = `rate_${rate}`;
  scenarios[name] = {
    executor: 'constant-arrival-rate',
    rate: rate,
    timeUnit: '1s',
    duration: STEP_DURATION,
    startTime: `${Math.round(i * STEP_SECONDS)}s`,
    // 1 VU par req/s absorbe jusqu'à ~1 s de latence par requête. Le plancher à
    // 10 évite que `maxVUs` passe sous `preAllocatedVUs` aux petits débits, ce
    // que k6 refuse net — et qui ferait échouer l'échelle entière, pas la marche.
    preAllocatedVUs: Math.max(10, rate),
    maxVUs: Math.max(20, rate * 2),
    gracefulStop: '30s',
    exec: 'hit',
  };

  // Seuils larges : l'échelle DOIT aller à son terme. On veut la courbe de
  // dégradation, pas un arrêt à la première marche qui déborde. Leur vrai rôle
  // est de matérialiser les sous-métriques par marche dans le résumé JSON.
  thresholds[`http_req_duration{scenario:${name}}`] = ['p(99)<60000'];
  thresholds[`http_req_failed{scenario:${name}}`] = ['rate<1'];
  thresholds[`http_reqs{scenario:${name}}`] = ['count>0'];
  // Le garde-fou. Déclaré pour être matérialisé, pas pour interrompre : c'est le
  // rapport qui refuse de publier la marche, après coup et par écrit.
  thresholds[`dropped_iterations{scenario:${name}}`] = ['count>=0'];
});

export const options = {
  scenarios: scenarios,
  summaryTrendStats: TREND_STATS,
  thresholds: thresholds,
  discardResponseBodies: true,
  noConnectionReuse: false,
};

export function hit() {
  workloads[WORKLOAD](__ITER);
}

export const handleSummary = makeHandleSummary(`ladder-${WORKLOAD}`);
