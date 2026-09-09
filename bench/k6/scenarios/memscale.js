// bench/k6/scenarios/memscale.js — la mémoire en fonction de la CONCURRENCE.
//
// C'est l'expérience que l'échelle de débit ne peut pas faire, et c'est celle
// qui porte la seule affirmation FinOps défendable du projet.
//
// ## Pourquoi un modèle FERMÉ ici, alors que le soak et l'échelle sont ouverts
//
// Le débit d'arrivée n'est pas la grandeur dont parle la promesse mémoire. Ce
// qui décide du nombre de processus PHP-FPM vivants à un instant donné, c'est le
// nombre de requêtes SIMULTANÉMENT EN VOL — pas le nombre qui arrive par seconde.
// Un modèle ouvert ne contrôle pas cette grandeur ; `constant-vus` la fixe
// exactement. C'est le même raisonnement que BENCH-05 côté écosystème, et c'est
// la seule place du harnais où le modèle fermé est le bon choix.
//
// ## Ce que la courbe doit montrer
//
//     RSS_fpm(c)      ~ linéaire en c — un enfant par requête en vol, chacun
//                       avec son propre tas et son propre bootstrap Symfony
//     RSS_worker(c)   ~ plat — un jeu fixe de workers sert toute la concurrence
//
// Le facteur d'économie se lit à la concurrence la plus haute que LES DEUX
// camps soutiennent — jamais à un point arbitraire, et jamais comme un chiffre
// plat. `bench/BENCH-RESULT.md` a déjà tranché ce point : la pente est
// publiable, le facteur plat ne l'est pas.
//
// ## Un sujet à la fois
//
// SUBJECT=rescue charge la passerelle (le monolithe n'est jamais touché) ;
// SUBJECT=legacy charge le monolithe en direct. Les deux séries ne sont JAMAIS
// produites dans la même exécution : elles se disputeraient les cœurs, et la
// courbe mesurerait la contention plutôt que l'empreinte. `perf-run.sh` les
// enchaîne, avec un repos entre les deux.
//
//   VUS_STEPS=8,16,32,64,128 SUBJECT=rescue bench/scripts/perf-run.sh memscale
import { check } from 'k6';
import { workloads, TREND_STATS, makeHandleSummary, durationToSeconds } from '../lib/common.js';

const SUBJECT = __ENV.SUBJECT || 'rescue';
if (!Object.prototype.hasOwnProperty.call(workloads, SUBJECT)) {
  throw new Error(`SUBJECT inconnu "${SUBJECT}" — attendu : rescue|shield|proxy|legacy`);
}

const VUS_STEPS = (__ENV.VUS_STEPS || '8,16,32,64,128')
  .split(',')
  .map((s) => parseInt(s.trim(), 10))
  .filter((n) => Number.isInteger(n) && n > 0);

const STEP_DURATION = __ENV.STEP_DURATION || '2m';
const STEP_SECONDS = durationToSeconds(STEP_DURATION);

const scenarios = {};
const thresholds = {};

// Une scénario `constant-vus` par marche, enchaînés par `startTime`. Des
// scénarios distincts plutôt que des `stages` : chaque marche garde ainsi ses
// propres métriques étiquetées, et l'analyse découpe le RSS par concurrence sans
// avoir à deviner les frontières à partir d'horodatages.
VUS_STEPS.forEach((vus, i) => {
  scenarios[`c_${vus}`] = {
    executor: 'constant-vus',
    vus: vus,
    duration: STEP_DURATION,
    startTime: `${Math.round(i * STEP_SECONDS)}s`,
    gracefulStop: '10s',
    tags: { concurrency: String(vus) },
    exec: 'step',
  };
  // Enregistrement seul : la saturation de la marche haute est un RÉSULTAT
  // attendu de cette expérience, pas un échec sur lequel interrompre.
  thresholds[`http_req_duration{concurrency:${vus}}`] = ['p(99)>=0'];
  thresholds[`http_reqs{concurrency:${vus}}`] = ['count>0'];
  thresholds[`http_req_failed{concurrency:${vus}}`] = ['rate<1'];
});

export const options = {
  scenarios: scenarios,
  summaryTrendStats: TREND_STATS,
  thresholds: thresholds,
  discardResponseBodies: true,
  noConnectionReuse: false,
};

export function step() {
  const res = workloads[SUBJECT](`${__VU}-${__ITER}`);
  check(res, { '2xx': (r) => r.status >= 200 && r.status < 300 }, { concurrency: 'all' });
  // Aucun `sleep` : un modèle fermé doit garder exactement `vus` requêtes en vol
  // pendant toute la marche, faute de quoi l'axe des abscisses ne décrit pas ce
  // qu'il prétend décrire.
}

// `LABEL` permet à perf-run.sh de donner un nom distinct à CHAQUE marche quand
// il les exécute une par une — ce qu'il fait délibérément. Enchaîner les marches
// dans une seule exécution obligerait à redécouper le relevé RSS a posteriori,
// d'après des horodatages, pour retrouver les frontières : une source d'erreur
// silencieuse que découper à la source supprime entièrement.
export const handleSummary = makeHandleSummary(__ENV.LABEL || `memscale-${SUBJECT}`);
