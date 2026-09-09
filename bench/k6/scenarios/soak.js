// bench/k6/scenarios/soak.js — endurance à débit d'arrivée constant.
//
// Modèle OUVERT (`constant-arrival-rate`), et c'est le choix structurant : un
// modèle fermé (`constant-vus`) réduit le débit dès que la latence monte, si
// bien qu'une passerelle qui ralentit reçoit MOINS de charge et se met à
// paraître saine. Le modèle ouvert maintient le débit demandé quoi qu'il arrive
// et laisse la latence dire la vérité — c'est le même choix que BENCH-02/03 côté
// écosystème.
//
// Ce que ce scénario mesure, et ce qu'il ne mesure pas :
//
//   MESURE   la latence des chemins servis PAR LA PASSERELLE (`rescue`,
//            `shield`), et l'étanchéité mémoire du worker sur la fenêtre.
//   NE MESURE PAS  une capacité maximale. Le débit est FIXÉ à 300 req/s et les
//            conteneurs sont bornés à 2 vCPU : le banc dit « la passerelle tient
//            ce débit sur cette capacité », jamais « voici son plafond ».
//
// L'étiquetage par chemin est ce qui rend les seuils honnêtes. La latence du
// chemin `proxy` est bornée par le monolithe (≈ 18 ms de bootstrap simulé, par
// construction), pas par la passerelle : la noyer dans un p(95) global
// produirait un chiffre qui ne décrit ni l'une ni l'autre.
import exec from 'k6/execution';
import { workloads, TREND_STATS, makeHandleSummary } from '../lib/common.js';

const RATE = parseInt(__ENV.RATE || '300', 10);
const DURATION = __ENV.DURATION || '3m';

// Motif pondéré sur 20 créneaux — rescue 60 %, shield 30 %, proxy 10 %.
//
// DÉTERMINISTE (indexé par le compteur global d'itérations, pas tiré au sort) :
// deux campagnes émettent exactement le même mélange, sans quoi comparer deux
// soaks reviendrait à comparer deux tirages.
//
// La part de `proxy` est délibérément minoritaire : à 10 % de 300 req/s, le
// monolithe reçoit 30 req/s, ce qu'il absorbe sans devenir le facteur limitant.
// Elle n'est pas nulle pour autant — une fuite se loge dans le chemin le moins
// exercé, et c'est le chemin `proxy` qui manipule les flux PSR-7 amont.
const PATTERN = [
  'rescue', 'shield', 'rescue', 'proxy', 'rescue', 'shield', 'rescue', 'shield',
  'rescue', 'rescue', 'shield', 'rescue', 'proxy', 'rescue', 'shield', 'rescue',
  'rescue', 'shield', 'rescue', 'rescue',
];

export const options = {
  scenarios: {
    soak: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: parseInt(__ENV.PRE_VUS || '20', 10),
      maxVUs: parseInt(__ENV.MAX_VUS || '60', 10),
      gracefulStop: '30s',
    },
  },
  summaryTrendStats: TREND_STATS,
  // Keep-Alive ACTIF. Le désactiver ouvrirait une connexion par requête : à
  // 300 req/s pendant 3 min, ce sont 54 000 sockets qui partent en TIME_WAIT
  // dans la plage éphémère de macOS. Le banc mesurerait alors l'épuisement des
  // ports de l'hôte et l'imputerait à la passerelle.
  noConnectionReuse: false,
  // Les corps ne sont pas lus : le générateur ne doit pas dépenser en parsing
  // le CPU qu'il doit consacrer à tenir le débit. Les en-têtes restent
  // disponibles — c'est le scénario `perimeter` qui les inspecte.
  discardResponseBodies: true,
  thresholds: {
    // Le SLA du sujet : les chemins servis par la passerelle elle-même.
    'http_req_duration{path:rescue}': ['p(95)<15', 'p(99)<30'],
    'http_req_duration{path:shield}': ['p(95)<15', 'p(99)<30'],
    // Enregistrement seul : borné par le monolithe, pas par la passerelle.
    // Une borne large qui n'interrompt rien — le soak DOIT aller à son terme,
    // sinon il n'y a pas de fenêtre de dérive à analyser.
    'http_req_duration{path:proxy}': ['p(99)<2000'],
    // Aucune erreur n'est tolérée, sur AUCUN chemin. Une passerelle qui perd une
    // requête sur mille perd 2,6 millions de requêtes par mois.
    http_req_failed: ['rate<0.001'],
    // Seuils triviaux, déclarés pour leur EFFET DE BORD : k6 ne matérialise une
    // sous-métrique étiquetée dans le résumé JSON que si un seuil la nomme.
    // Sans ces trois lignes, le rapport ne saurait pas combien de requêtes ont
    // emprunté chaque chemin — et un motif de charge qui aurait dérivé passerait
    // inaperçu. Même procédé que le banc écosystème (`constant-load.js`).
    'http_reqs{path:rescue}': ['count>0'],
    'http_reqs{path:shield}': ['count>0'],
    'http_reqs{path:proxy}': ['count>0'],
  },
};

export default function () {
  const name = PATTERN[exec.scenario.iterationInTest % PATTERN.length];
  // Le chemin `proxy` doit être unique par itération, sinon le Shield le
  // mettrait en cache et l'on cesserait de mesurer un proxy.
  workloads[name](exec.scenario.iterationInTest);
}

export const handleSummary = makeHandleSummary('soak');
