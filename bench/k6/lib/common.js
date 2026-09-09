// bench/k6/lib/common.js — socle commun des scénarios k6 natifs d'EcoShield.
//
// Ce dossier est le pendant « générateur sur l'hôte » du harnais historique
// (`bench/*.js`, k6 dans le réseau compose). Les deux mesurent la même
// passerelle ; seul change l'endroit d'où part la charge, et c'est précisément
// la variable que `bench/BENCH-RESULT.md` désigne comme la limite principale de
// la campagne beta6 : « un chiffre de capacité honnête exige un générateur de
// charge sur une machine distincte ».
//
// À défaut d'une seconde machine, la topologie retenue est la plus proche
// disponible : k6 natif sur l'hôte macOS, conteneurs bornés en CPU
// (`docker-compose.perf.yml`), de sorte que le générateur ne dispute jamais ses
// cœurs à la passerelle qu'il mesure.
//
// La structure suit celle du banc écosystème beta6 (`../../bench/k6/` du
// monorepo) : un socle partagé, des scénarios qui n'en dévient pas, et un
// `handleSummary` unique pour que tous les résultats aient la même forme.

import http from 'k6/http';
import crypto from 'k6/crypto';

// L'hôte, pas le réseau compose : le port publié par la passerelle.
export const BASE_URL = __ENV.TARGET || 'http://localhost:8099';

// L'amont joint SANS passer par la passerelle. Sert de référence sous la même
// topologie bornée — comparer aux chiffres non bornés d'hier comparerait deux
// bancs, pas deux architectures.
export const LEGACY_URL = __ENV.LEGACY_TARGET || 'http://localhost:8098';

// Statistiques que tout scénario expose. p(99.9) est là pour la même raison que
// dans le banc écosystème : une queue de distribution invisible en p(99) est
// exactement là que se logent les réveils de cache et les pauses d'allocateur.
export const TREND_STATS = ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'p(99.9)', 'max'];

// ---------------------------------------------------------------------------
// Les trois chemins, et pourquoi les trois
// ---------------------------------------------------------------------------
// Une fuite se cache dans le chemin le moins exercé : le soak couvre donc les
// trois piliers du POC, pas seulement le plus rapide.
//
//  - `rescue` — route reprise, servie par le worker. Le monolithe n'est jamais
//    contacté : c'est la latence de la passerelle SEULE, donc le seul chemin sur
//    lequel un SLA de passerelle veut dire quelque chose.
//  - `shield`  — même cible pour tous les VUs, donc mutualisable : après le
//    premier passage, la réponse sort du cache sans traverser le réseau.
//  - `proxy`   — paramètre unique par itération, donc jamais mutualisable :
//    chaque requête traverse réellement jusqu'au monolithe. Sa latence est
//    bornée par le monolithe, pas par la passerelle.
//
// Chaque requête est ÉTIQUETÉE avec son chemin. C'est ce qui permet d'appliquer
// un seuil au seul chemin qu'il décrit : mélanger les trois dans un p(95) global
// produirait un chiffre qui ne caractérise rien.
export const workloads = {
  rescue: () => http.get(`${BASE_URL}/api/products/42`, { tags: { path: 'rescue' } }),
  shield: () => http.get(`${BASE_URL}/api/catalogue`, { tags: { path: 'shield' } }),
  proxy: (unique) => http.get(`${BASE_URL}/api/orders/7?nocache=${unique}`, { tags: { path: 'proxy' } }),
  legacy: () => http.get(`${LEGACY_URL}/api/products/42`, { tags: { path: 'legacy' } }),

  // --- Les deux charges qui portent désormais la comparaison ---------------
  // Même requête, même base, même ligne, de part et d'autre. C'est le couple qui
  // remplace « statique contre statique » : tant que le monolithe interrogeait
  // une base et la passerelle non, l'écart mesuré contenait le coût d'un SELECT
  // et pas seulement celui d'un démarrage de framework.
  dbread: () => http.get(`${BASE_URL}/api/users/${randomSeededId()}`, { tags: { path: 'dbread' } }),
  legacydb: () => http.get(`${LEGACY_URL}/api/users/${randomSeededId()}`, { tags: { path: 'legacydb' } }),
};

// ---------------------------------------------------------------------------
// Dérivation des identifiants — DOIT suivre bench/sql/init.sql à la lettre
// ---------------------------------------------------------------------------
// Le jeu de données est amorcé avec des identifiants dérivés, de sorte que k6
// puisse en calculer un sans jamais interroger la base :
//
//   h  = md5('bench-user-' + n)   pour n de 1 à 10000
//   id = h découpé en 8-4-4-4-12
//
// Le banc écosystème consigne une panne exactement ici : une première version
// dérivait `md5(String(n))`, si bien que TOUTE lecture manquait sa ligne. Le
// camp qui répondait « 200 found:false » masquait le problème, celui qui
// répondait 404 le révélait — et les deux mesuraient un index qui ne rend rien.
// La règle qui en découle : après toute modification d'un côté, vérifier qu'une
// lecture trouve bien sa ligne AVANT de publier le moindre chiffre.
export const SEEDED_ROWS = 10000;

export function existingId(rowNumber) {
  const hex = crypto.md5(`bench-user-${rowNumber}`, 'hex');
  return (
    hex.slice(0, 8) + '-' +
    hex.slice(8, 12) + '-' +
    hex.slice(12, 16) + '-' +
    hex.slice(16, 20) + '-' +
    hex.slice(20)
  );
}

// Un identifiant tiré au hasard dans le jeu amorcé. Le tirage est délibéré :
// frapper toujours la même ligne mesurerait un cache de page PostgreSQL chaud
// d'une seule entrée, pas une lecture indexée.
export function randomSeededId() {
  return existingId(1 + Math.floor(Math.random() * SEEDED_ROWS));
}

// ---------------------------------------------------------------------------
// Résumé — un JSON par scénario, dans bench/results/
// ---------------------------------------------------------------------------
// Le rapport est produit par `bench/scripts/perf-report.py` à partir de ce
// fichier : le digest stdout n'est qu'une commodité de lecture pendant la
// campagne, jamais la source d'un chiffre publié.
export function makeHandleSummary(scenario) {
  const path = `bench/results/perf-${scenario}.json`;

  return function (data) {
    data.bench = {
      scenario: scenario,
      target: BASE_URL,
      generator: 'k6 natif (hôte macOS)',
      env: {
        RATE: __ENV.RATE || null,
        DURATION: __ENV.DURATION || null,
        WARMUP: __ENV.WARMUP || null,
      },
    };

    const out = { stdout: digest(data, scenario) };
    out[path] = JSON.stringify(data, null, 2);
    return out;
  };
}

// Accès défensif aux métriques : k6 a déjà déplacé les valeurs entre
// `metric.values.x` et `metric.x` d'une version majeure à l'autre, et un rapport
// qui tombe en marche sur une montée de version ne vaut rien.
export function metricValues(data, name) {
  const metric = (data.metrics || {})[name];
  if (!metric) return {};
  return metric.values || metric;
}

// Convertit '2m' / '90s' / '1h30m' en secondes. Les scénarios à marches en ont
// besoin pour calculer les `startTime` qui enchaînent les marches bout à bout :
// k6 ne sait pas dire « démarre quand la précédente finit », il faut lui donner
// un décalage absolu, donc savoir compter la durée d'une marche.
export function durationToSeconds(d) {
  const re = /(\d+(?:\.\d+)?)(h|m|s|ms)/g;
  let total = 0;
  let matched = false;
  let m;
  while ((m = re.exec(String(d))) !== null) {
    matched = true;
    const v = parseFloat(m[1]);
    if (m[2] === 'h') total += v * 3600;
    else if (m[2] === 'm') total += v * 60;
    else if (m[2] === 's') total += v;
    else total += v / 1000;
  }
  if (!matched) {
    const bare = parseFloat(String(d));
    if (!Number.isNaN(bare)) return bare; // nombre nu = secondes
    throw new Error(`Durée illisible : "${d}"`);
  }
  return total;
}

function num(v, digits = 2) {
  return v === undefined || v === null ? 'n/a' : Number(v).toFixed(digits);
}

function digest(data, scenario) {
  const reqs = metricValues(data, 'http_reqs');
  const dur = metricValues(data, 'http_req_duration');
  const failed = metricValues(data, 'http_req_failed');

  return [
    '',
    `résumé  scénario=${scenario}  cible=${BASE_URL}`,
    `  requêtes   total=${num(reqs.count, 0)}  débit=${num(reqs.rate)}/s`,
    `  latence    med=${num(dur.med)}ms  p(95)=${num(dur['p(95)'])}ms  ` +
      `p(99)=${num(dur['p(99)'])}ms  max=${num(dur.max)}ms`,
    `  échecs     ${num((failed.rate || 0) * 100)}%`,
    `  json    -> bench/results/perf-${scenario}.json`,
    '',
  ].join('\n');
}
