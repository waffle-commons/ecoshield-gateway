// bench/k6/lib/warmup.js — préchauffe, et RIEN D'AUTRE.
//
// Ce fichier existe pour une raison précise : la préchauffe ne doit produire
// AUCUN résumé. En réutilisant le scénario de soak pour préchauffer, le passage
// de chauffe écrivait `bench/results/perf-soak.json` exactement comme une
// mesure — même nom, même forme, aucun moyen de les distinguer. Tant que le
// soak réel suivait, le fichier finissait par contenir la bonne série ; mais un
// soak interrompu aurait laissé en place le résumé de la PRÉCHAUFFE, et le
// rapport aurait publié 100 req/s de chauffe comme s'il s'agissait de la
// campagne. Le banc écosystème isole la préchauffe pour la même raison.
//
// Ce que la préchauffe sert à obtenir avant que le chronomètre ne parte :
// l'opcache rempli, les routes compilées et mises en cache, les connexions
// Redis établies, et la première entrée du Shield écrite — sans quoi la
// première seconde de mesure publierait le coût du premier passage comme s'il
// était le régime permanent.
import exec from 'k6/execution';
import { workloads, TREND_STATS } from './common.js';

export const options = {
  scenarios: {
    warmup: {
      executor: 'constant-arrival-rate',
      rate: parseInt(__ENV.RATE || '100', 10),
      timeUnit: '1s',
      duration: __ENV.DURATION || '30s',
      preAllocatedVUs: 10,
      maxVUs: 30,
      gracefulStop: '5s',
    },
  },
  summaryTrendStats: TREND_STATS,
  discardResponseBodies: true,
  // Aucun seuil : une préchauffe qui « échoue » n'a pas de sens, et un seuil
  // rouge ici ferait échouer la campagne avant qu'elle n'ait commencé.
};

export default function () {
  const i = exec.scenario.iterationInTest;
  workloads.rescue();
  workloads.shield();
  workloads.proxy(i);
}

export function handleSummary() {
  return { stdout: '\npréchauffe terminée (aucun résumé enregistré)\n' };
}
