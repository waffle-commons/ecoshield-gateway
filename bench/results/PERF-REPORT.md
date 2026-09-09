# Campagne de mesure — générateur natif

Produit par `bench/scripts/perf-report.py`. Les chiffres viennent des fichiers bruts de `bench/results/` ; aucun n'est saisi à la main.

## Topologie mesurée

| | |
|---|---|
| Hôte | MacBookPro15,1 · 6 cœurs physiques / 12 logiques · 16 Gio |
| Système | Darwin 24.6.0 (macOS 15.7.9) |
| Docker | 29.7.2 · VM 12 vCPU / 13.6 Gio |
| Générateur | k6 v2.0.0 (commit/devel, go1.26.3, darwin/amd64), **natif sur l'hôte** |
| FrankenPHP | 4 threads · **2 workers** résidents |
| Passerelle | bornée à 2.0 vCPU · 1.0 Gio |
| `MAX_REQUESTS` | 1000000 (recyclage worker neutralisé) |
| Sockets hôte | `somaxconn`=128 · `tcp.msl`=15000 · `ulimit -n`=1048576 |

## Débit et latence

**54001 requêtes** à **300.00 req/s** soutenues · taux d'échec **0.000 %** (0 requêtes en échec sur 54001).

Latence en millisecondes, par chemin — les trois ne mesurent pas la même chose :

| Chemin | requêtes | min | p50 | p90 | p95 | p99 | p99.9 | max |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `rescue` — servi par le worker | 32401 | 0.72 | 1.23 | 1.64 | 1.81 | 2.19 | 4.16 | 20.15 |
| `shield` — servi du cache | 16200 | 0.98 | 1.54 | 2.01 | 2.15 | 2.57 | 9.38 | 30.19 |
| `proxy` — traverse jusqu'au monolithe | 5400 | 19.30 | 21.60 | 22.66 | 22.87 | 23.40 | 28.12 | 35.75 |

Toutes requêtes confondues : p50 1.38 ms · p95 21.60 ms · p99 22.66 ms · max 35.75 ms. *Ce chiffre global mélange trois chemins de coûts différents ; il est donné pour mémoire, pas comme un SLA.*

### Seuils déclarés

| Seuil | Verdict |
|---|---|
| `http_req_duration{path:proxy}` p(99)<2000 | PASS |
| `http_req_duration{path:rescue}` p(95)<15 | PASS |
| `http_req_duration{path:rescue}` p(99)<30 | PASS |
| `http_req_duration{path:shield}` p(95)<15 | PASS |
| `http_req_duration{path:shield}` p(99)<30 | PASS |
| `http_req_failed` rate<0.001 | PASS |
| `http_reqs{path:proxy}` count>0 | PASS |
| `http_reqs{path:rescue}` count>0 | PASS |
| `http_reqs{path:shield}` count>0 | PASS |

## Invariant mémoire

### Tas PHP du worker — 89 relevés sur 3.0 min

Granularité à l'octet : c'est la seule résolution capable de trancher un critère exprimé en kio. Les deux enveloppes séparent les workers, dont les tas alternent d'un relevé à l'autre.

| Série | début | fin | Δ | pente (IC 95 %) | verdict |
|---|---:|---:|---:|---:|---|
| enveloppe haute (kio) | 888.10 | 888.10 | +0.00 | -760.48 kio/h (±1310.19) | compatible avec zéro |
| enveloppe basse (kio) | 820.12 | 820.12 | +0.00 | +0.00 kio/h (±0.00) | compatible avec zéro |
| tas brut — *indicatif* | 888.10 | 888.10 | +0.00 | -472.98 kio/h (±425.83) | — |
| pic cumulé (kio) | 1021.95 | 1021.95 | +0.00 | +0.00 kio/h (±0.00) | compatible avec zéro |
| blocs OS (Mio) | 2.00 | 2.00 | +0.00 | +0.00 Mio/h (±0.00) | compatible avec zéro |

> **La ligne « tas brut » ne porte volontairement aucun verdict.** La série brute alterne entre les tas de plusieurs workers selon celui qui a servi le relevé : une régression dessus mesure surtout la proportion de relevés tombés sur l'un ou l'autre, et peut afficher une pente franche alors que le début et la fin sont rigoureusement identiques. Le verdict appartient aux deux enveloppes — si aucune ne monte, aucun worker ne fuit.

**ΔM (critère écosystème BENCH-03)** — moyenne des 20 % finaux moins moyenne des 20 % initiaux, sur l'enveloppe haute : **-22.7 kio**.

**Critère d'endurance (≤ 256 kio de dérive nette) : PASS** — |-22.7| kio contre 256 kio autorisés, sur le tas PHP du worker, seule grandeur dont la résolution permette de trancher un seuil exprimé en kio.

### RSS conteneur — Passerelle (`ecoshield-gateway`)

29 relevés sur 2.9 min.

| Série | début | fin | Δ | pente (IC 95 %) | verdict |
|---|---:|---:|---:|---:|---|
| RSS (Mio) | 47.59 | 48.01 | +0.42 | +8.13 Mio/h (±5.60) | **hors borne** |

> **Ce que cette fenêtre ne peut pas dire.** La pente est exprimée par heure à partir de 2.9 min de relevés : l'extrapolation multiplie le bruit par 21. Une pente qui sort de sa borne ici ne démontre PAS une fuite — elle dit que la fenêtre est trop courte pour trancher, et c'est exactement la limite que `BENCH-03` (beta6) traitait en soakant 3 h par moteur. Le chiffre solide sur une fenêtre courte est le Δ observé, pas son extrapolation.

### RSS conteneur — Monolithe (PHP-FPM) (`ecoshield-legacy-fpm`)

29 relevés sur 2.9 min.

| Série | début | fin | Δ | pente (IC 95 %) | verdict |
|---|---:|---:|---:|---:|---|
| RSS (Mio) | 56.83 | 56.81 | -0.02 | +2.57 Mio/h (±6.01) | compatible avec zéro |

> **Ce que cette fenêtre ne peut pas dire.** La pente est exprimée par heure à partir de 2.9 min de relevés : l'extrapolation multiplie le bruit par 20. Une pente qui sort de sa borne ici ne démontre PAS une fuite — elle dit que la fenêtre est trop courte pour trancher, et c'est exactement la limite que `BENCH-03` (beta6) traitait en soakant 3 h par moteur. Le chiffre solide sur une fenêtre courte est le Δ observé, pas son extrapolation.

## Périmètre — assertions négatives

> **Lecture.** k6 compte ici un taux d'échec HTTP de 63.6 % : c'est ATTENDU et c'est le résultat recherché. Ce scénario envoie majoritairement des requêtes qui DOIVENT être refusées, et un 400 compte comme un échec HTTP pour le générateur. Le verdict de ce scénario est la colonne des assertions ci-dessous, jamais `http_req_failed`.

| Assertion | passées | échouées | Verdict |
|---|---:|---:|---|
| host_forge → 400 | 20 | 0 | OK |
| traversal_encoded → 400 | 20 | 0 | OK |
| traversal_double_encoded → 400 | 20 | 0 | OK |
| traversal_backslash → 400 | 20 | 0 | OK |
| traversal_too_deep → 400 | 20 | 0 | OK |
| protocol_relative → 400 | 20 | 0 | OK |
| null_byte → 400 | 20 | 0 | OK |
| la sortie reste épinglée sur l amont configuré | 20 | 0 | OK |
| X-Forwarded-Host forge est ecrase | 20 | 0 | OK |
| le pair reel est ajoute en fin de chaine | 20 | 0 | OK |
| Forwarded et X-Real-IP sont supprimes | 20 | 0 | OK |
| une requete porteuse de Cookie n est jamais servie du cache | 20 | 0 | OK |
| une requete porteuse d Authorization n est jamais servie du cache | 20 | 0 | OK |
| un en-tete d identite arbitraire traverse tel quel (constat, pas garantie) | 20 | 0 | OK |

**280 assertions passées, 0 échouées.** Aucun contournement observé.

### Cadrage des messages (socket brute)

Cible : http://localhost:8099 · 2026-09-09T08:37:16Z

| Cas | Réponses | Statut | Refusé par | Verdict |
|---|---:|---:|---|---|
| cl_te_smuggling | 1 | 200 | indéterminé | OK |
| te_cl_smuggling | 1 | 200 | indéterminé | OK |
| dual_content_length | 1 | 400 | Caddy (avant PHP) | OK |
| controle_positif | 1 | 200 | indéterminé | OK |

**Verdict : aucun désync.** Aucune requête ambiguë n a produit plus d une réponse.

> sockets TIME_WAIT sur l hôte après la charge : 36

