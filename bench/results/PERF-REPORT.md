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

**3239963 requêtes** à **299.99 req/s** soutenues · taux d'échec **0.000 %** (0 requêtes en échec sur 3239963).

Latence en millisecondes, par chemin — les trois ne mesurent pas la même chose :

| Chemin | requêtes | min | p50 | p90 | p95 | p99 | p99.9 | max |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `rescue` — servi par le worker | 1943978 | 0.05 | 1.22 | 1.60 | 1.74 | 2.05 | 3.17 | 129.77 |
| `shield` — servi du cache | 971989 | 0.93 | 1.51 | 2.01 | 2.17 | 2.57 | 4.56 | 128.69 |
| `proxy` — traverse jusqu'au monolithe | 323996 | 2.93 | 4.54 | 5.51 | 5.76 | 6.91 | 9.97 | 146.72 |

Toutes requêtes confondues : p50 1.37 ms · p95 4.56 ms · p99 5.52 ms · max 146.72 ms. *Ce chiffre global mélange trois chemins de coûts différents ; il est donné pour mémoire, pas comme un SLA.*

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

### Tas PHP du worker — 5308 relevés sur 180.0 min

> Cadence de relevé saine : médiane 2.0 s, pire écart 3.0 s (nominal 2 s). L'instrument a tenu.

Granularité à l'octet : c'est la seule résolution capable de trancher un critère exprimé en kio. Les deux enveloppes séparent les workers, dont les tas alternent d'un relevé à l'autre.

| Série | début | fin | Δ | pente (IC 95 %) | verdict |
|---|---:|---:|---:|---:|---|
| enveloppe haute (kio) | 967.06 | 835.22 | -131.84 | -59.03 kio/h (±25.59) | **hors borne** |
| enveloppe basse (kio) | 835.22 | 494.09 | -341.12 | -134.25 kio/h (±66.30) | **hors borne** |
| tas brut — *indicatif* | 967.05 | 494.09 | -472.96 | -135.40 kio/h (±5.06) | — |
| pic cumulé (kio) | 1048.57 | 647.82 | -400.75 | -115.76 kio/h (±5.28) | **hors borne** |
| blocs OS (Mio) | 2.00 | 2.00 | +0.00 | +0.00 Mio/h (±0.00) | compatible avec zéro |

> **La ligne « tas brut » ne porte volontairement aucun verdict.** La série brute alterne entre les tas de plusieurs workers selon celui qui a servi le relevé : une régression dessus mesure surtout la proportion de relevés tombés sur l'un ou l'autre, et peut afficher une pente franche alors que le début et la fin sont rigoureusement identiques. Le verdict appartient aux deux enveloppes — si aucune ne monte, aucun worker ne fuit.

**ΔM (critère écosystème BENCH-03)** — moyenne des 20 % finaux moins moyenne des 20 % initiaux, sur l'enveloppe haute : **-131.8 kio**.

**Critère d'endurance (≤ 256 kio de dérive nette) : PASS** — |-131.8| kio contre 256 kio autorisés, sur le tas PHP du worker, seule grandeur dont la résolution permette de trancher un seuil exprimé en kio.

### RSS conteneur — Passerelle (`ecoshield-gateway`)

1782 relevés sur 180.0 min.

| Série | début | fin | Δ | pente (IC 95 %) | verdict |
|---|---:|---:|---:|---:|---|
| RSS (Mio) | 51.20 | 48.02 | -3.18 | +0.00 Mio/h (±0.03) | compatible avec zéro |

### RSS conteneur — Monolithe (PHP-FPM) (`ecoshield-legacy-fpm`)

1781 relevés sur 179.9 min.

| Série | début | fin | Δ | pente (IC 95 %) | verdict |
|---|---:|---:|---:|---:|---|
| RSS (Mio) | 18.86 | 19.49 | +0.63 | +0.01 Mio/h (±0.02) | compatible avec zéro |

## Échelle de débit — charge « dbread »

Modèle **ouvert** : le débit demandé est maintenu quoi qu'il arrive, et c'est la latence qui encaisse. Un modèle fermé réduirait la charge dès que le sujet ralentit, et un sujet qui ralentit paraîtrait sain.

| Débit visé | Débit atteint | p50 | p95 | p99 | échecs | itérations perdues | Lecture |
|---:|---:|---:|---:|---:|---:|---:|---|
| 50 req/s | 50.01 | 4.43 | 5.78 | 7.06 | 0.00 % | 0 | débit tenu |
| 100 req/s | 100.01 | 3.82 | 5.04 | 5.91 | 0.00 % | 0 | débit tenu |
| 200 req/s | 200.01 | 2.36 | 3.22 | 3.74 | 0.00 % | 0 | débit tenu |
| 400 req/s | 400.01 | 2.08 | 2.88 | 3.72 | 0.00 % | 0 | débit tenu |
| 800 req/s | 800.00 | 2.59 | 4.03 | 11.63 | 0.00 % | 0 | débit tenu |

> **La colonne « Lecture » n'est pas un avis, c'est une règle mécanique.** k6 incrémente `dropped_iterations` quand son exécuteur ne parvient pas à émettre à la cadence demandée — VUs tous occupés, ou générateur à court de CPU. Une marche où ce compteur est non nul n'a pas subi la charge annoncée : sa latence est celle d'un débit plus faible, et la publier comme un chiffre de capacité serait un mensonge par omission. C'est ce garde-fou qui remplace, sur une seule machine, la séparation physique du générateur et de son sujet.

> Aucune marche écartée : le générateur a tenu la cadence sur toute l'échelle, et chaque ligne décrit bien le sujet.

## Échelle de débit — charge « legacydb »

Modèle **ouvert** : le débit demandé est maintenu quoi qu'il arrive, et c'est la latence qui encaisse. Un modèle fermé réduirait la charge dès que le sujet ralentit, et un sujet qui ralentit paraîtrait sain.

| Débit visé | Débit atteint | p50 | p95 | p99 | échecs | itérations perdues | Lecture |
|---:|---:|---:|---:|---:|---:|---:|---|
| 50 req/s | 48.84 | 16.79 | 19.39 | 21.64 | 0.00 % | 0 | débit tenu |
| 100 req/s | 97.69 | 19.90 | 22.70 | 25.01 | 0.00 % | 0 | débit tenu |
| 200 req/s | 111.18 | 3557.92 | 3977.03 | 4150.29 | 0.00 % | 10343 | **sujet saturé** |
| 400 req/s | 111.02 | 7204.77 | 7682.33 | 7892.00 | 0.00 % | 34365 | **sujet saturé** |
| 800 req/s | 115.57 | 14326.25 | 14996.04 | 17095.22 | 0.00 % | 81803 | **sujet saturé** |

> **Saturation du sujet à partir de 200 req/s.** Au-delà, le débit atteint plafonne et la latence part en secondes : ces lignes ne sont pas des chiffres de latence *au débit visé* — le débit visé n'a jamais été servi — mais elles sont la MESURE du genou, et c'est le résultat que l'échelle existe pour produire. Le plafond réel se lit dans la colonne « débit atteint », pas dans la colonne « visé ».

> **La colonne « Lecture » n'est pas un avis, c'est une règle mécanique.** k6 incrémente `dropped_iterations` quand son exécuteur ne parvient pas à émettre à la cadence demandée — VUs tous occupés, ou générateur à court de CPU. Une marche où ce compteur est non nul n'a pas subi la charge annoncée : sa latence est celle d'un débit plus faible, et la publier comme un chiffre de capacité serait un mensonge par omission. C'est ce garde-fou qui remplace, sur une seule machine, la séparation physique du générateur et de son sujet.

## Empreinte mémoire en fonction de la concurrence

Modèle **fermé** ici, et c'est le seul endroit du harnais où il est correct : ce qui décide du nombre d'enfants PHP-FPM vivants n'est pas le débit d'arrivée mais le nombre de requêtes SIMULTANÉMENT en vol, et seul `constant-vus` fixe cette grandeur.

### dbread

| Concurrence | p50 | p95 | débit | RSS crête |
|---:|---:|---:|---:|---:|
| 8 | 6.53 ms | 8.23 ms | 1178.85 req/s | 84.79 Mio |
| 16 | 13.65 ms | 17.02 ms | 1152.46 req/s | 84.81 Mio |
| 32 | 27.32 ms | 34.31 ms | 1155.53 req/s | 84.88 Mio |
| 64 | 57.17 ms | 68.84 ms | 1119.08 req/s | 84.78 Mio |
| 128 | 114.32 ms | 142.00 ms | 1104.84 req/s | 85.00 Mio |

Pente (c=8–128) : **+0.0015 Mio par requête concurrente**.

### legacydb

| Concurrence | p50 | p95 | débit | RSS crête |
|---:|---:|---:|---:|---:|
| 8 | 81.34 ms | 90.73 ms | 124.69 req/s | 37.73 Mio |
| 16 | 111.63 ms | 202.33 ms | 118.07 req/s | 50.53 Mio |
| 32 | 293.38 ms | 490.93 ms | 109.09 req/s | 74.48 Mio |
| 64 | 582.82 ms | 987.87 ms | 110.09 req/s | 112.00 Mio |
| 128 | 1177.99 ms | 1587.74 ms | 109.08 req/s | 112.60 Mio |

Pente sur la région non saturée (c=8–64) : **+1.3149 Mio par requête concurrente**.

> **Marches écartées de la régression : c=128.** Le RSS y cesse de croître alors que la concurrence double — signature d'un plafond de processus (`pm.max_children`), pas d'une empreinte qui se stabilise. Les inclure ramènerait la pente à +0.6156 Mio/requête et publierait un sujet plus sobre qu'il n'est.

**Le résultat est la PENTE, pas un facteur plat.** Le monolithe paie **+1.3149 Mio par requête concurrente** ; la passerelle **+0.0015**, c'est-à-dire une empreinte constante quelle que soit la charge.

**Croisement à ≈ 44 requêtes concurrentes.** En dessous, la passerelle consomme DAVANTAGE — elle garde le framework résident quand le monolithe ne garde rien. Au-dessus, l'écart se creuse indéfiniment. C'est à partir de ce seuil, et pas avant, qu'un argument d'économie mémoire est défendable.

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

Cible : http://localhost:8099 · 2026-09-09T13:27:51Z

| Cas | Réponses | Statut | Refusé par | Verdict |
|---|---:|---:|---|---|
| cl_te_smuggling | 1 | 405 | indéterminé | OK |
| te_cl_smuggling | 1 | 405 | indéterminé | OK |
| dual_content_length | 1 | 400 | Caddy (avant PHP) | OK |
| controle_positif | 1 | 200 | indéterminé | OK |

**Verdict : aucun désync.** Aucune requête ambiguë n a produit plus d une réponse.

> sockets TIME_WAIT sur l hôte après la charge : 44

