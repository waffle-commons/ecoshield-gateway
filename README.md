# 🛡️ EcoShield Gateway

**Passerelle d'API FinOps pour monolithes PHP legacy.**
_Moderniser, sécuriser et réduire la facture d'hébergement — sans réécrire l'application._

[![Licence MIT](https://img.shields.io/badge/licence-MIT-green.svg)](./LICENSE)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777bb4.svg)](https://www.php.net/)
[![FrankenPHP](https://img.shields.io/badge/runtime-FrankenPHP%20worker-blue.svg)](https://frankenphp.dev/)
[![Mesures](https://img.shields.io/badge/mesures-rapport%20reproductible-blue.svg)](./BENCHMARK_RESULTS.md)

---

## 🎯 Le problème : le mur de la dette

Une application PHP traditionnelle (Nginx + PHP-FPM) **reconstruit son framework à chaque requête
HTTP**. Conteneur d'injection, routeur, configuration, autoloading : tout est rejoué, puis jeté.

Sous charge, la conséquence est financière avant d'être technique. La mémoire consommée croît avec le
nombre de requêtes simultanées — **+1.31 Mio par requête concurrente, mesurés** sur un Symfony 5.4
de production (§ Résultats) —
ce qui oblige à surdimensionner les serveurs pour absorber les pics : Black Friday, campagne
marketing, pic saisonnier. Le reste de l'année, cette capacité est payée sans être utilisée.

Les deux issues habituelles sont mauvaises :

| Option | Coût | Risque |
|---|---|---|
| Surdimensionner l'infrastructure | Récurrent, et croissant avec le trafic | Aucun gain fonctionnel en contrepartie |
| Réécrire l'application | Engagé en totalité avant le premier bénéfice | Élevé — aucun gain tant que le périmètre existant n'est pas reproduit à l'identique |

**EcoShield propose une troisième voie**, incrémentale et réversible.

---

## 💡 La solution : le pattern « Strangler Fig »

EcoShield se déploie **en amont** de l'application existante, qui n'est pas modifiée. Construit sur
le framework [Waffle-Commons](https://github.com/waffle-commons) (PHP 8.5 strict, exécuté en mémoire
résidente via FrankenPHP), il intercepte le trafic entrant et le traite en trois temps :

```
                    ┌──────────────────────────────────────┐
   Trafic  ──────►  │           EcoShield Gateway          │
                    │        (FrankenPHP, mode worker)     │
                    ├──────────────────────────────────────┤
                    │ 1. RESCUE  route reprise ──► réponse │  3.82 ms
                    │ 2. SHIELD  cache partagé  ──► réponse│  1.51 ms
                    │ 3. PROXY   tout le reste             │
                    └───────────────────┬──────────────────┘
                                        ▼
                            ┌───────────────────────┐
                            │  Monolithe Symfony    │ 19.90 ms
                            │  (Nginx + PHP-FPM)    │  intact, non modifié
                            └───────────────────────┘
```

_Lectures en base indexée, à 100 req/s tenus des deux côtés ; la même requête sur la même ligne de la
même base. Le chemin SHIELD sert depuis le cache et n'atteint pas le monolithe (mesuré sous
endurance, 300 req/s)._

1. **Interception (Rescue).** Les routes critiques ou coûteuses sont reprises une à une et servies
   nativement depuis le worker, sans jamais atteindre le monolithe. Reprendre une route consiste à en
   ajouter une dans la passerelle : **ni le routage du legacy ni les URL vues par les clients ne
   changent.**
2. **Proxy transparent.** Tout le trafic non repris est relayé vers l'application existante, en flux,
   sans mise en mémoire tampon du corps des messages.
3. **Bouclier (Shield).** Les réponses mutualisables du legacy sont mises en cache, ce qui décharge
   la base de données sous-jacente — le poste le plus difficile à dimensionner.

La migration est donc **progressive, mesurable et réversible** : chaque route reprise est un gain
constaté, et retirer la passerelle rétablit l'état initial.

---

## 📊 Résultats mesurés

Ces chiffres ne sont pas des objectifs : ils sortent du banc versionné dans [`bench/`](./bench),
rejouable en une commande. Le protocole complet — **y compris ce que ce banc ne permet PAS de
conclure** — est dans **[`BENCHMARK_RESULTS.md`](./BENCHMARK_RESULTS.md)**.

**Le sujet de comparaison est un vrai monolithe Symfony 5.4 LTS**, sur PHP 8.3 avec opcache, lisant
la même ligne de la même base PostgreSQL que la passerelle, avec la même requête. Une seule variable
sépare les deux camps : le framework reste-t-il en mémoire entre deux requêtes, ou est-il reconstruit
à chaque fois.

| Indicateur | Mesure | Lecture |
|---|---|---|
| **Latence, lecture en base** à 100 req/s tenus des deux côtés | **3.82 ms** contre 19.90 ms | **÷5.2** |
| **Débit maximal** (2 vCPU par camp) | **~1 165 req/s** contre ~111 req/s | **×10.5** |
| **Croissance mémoire** | **+0.0015 Mio** par requête concurrente, contre **+1.31 Mio** pour PHP-FPM | empreinte plate contre linéaire |
| **Stabilité dans le temps** | **+0.00 Mio/h (±0.03)** sur 3 h et 3 239 963 requêtes, **0 erreur** | fuite exclue au-delà de ~0.7 Mio/jour |
| **Latence sous endurance** | p95 **1.74 ms**, p99 **2.05 ms** sur 3 h à 300 req/s | budget de 15 ms tenu avec un facteur 8,6 |

Sous saturation, l'écart change de nature : au-delà de 200 req/s demandés, le monolithe en sert
toujours ~111 et sa latence passe de 20 ms à **14 secondes**, quand la passerelle tient 800 req/s à
2.59 ms sans perdre une requête. Aucun des deux ne rend d'erreur HTTP — PHP-FPM met en file.

### Ce que ces chiffres ne disent pas

Un banc qui ne publie que ses bons résultats n'est pas un banc. Cinq réserves, toutes documentées :

- **En dessous d'environ 44 requêtes simultanées, la passerelle coûte PLUS cher** en mémoire qu'un
  PHP-FPM : 84.8 Mio contre 37.7 Mio à 8 requêtes en vol, soit 2,2× plus. Un worker résident garde le
  framework en mémoire en permanence, là où le monolithe ne garde rien. **EcoShield est pertinent
  pour un trafic soutenu, pas pour une application peu sollicitée** — et le seuil se mesure, il ne
  se devine pas.
- **L'écart n'est pas seulement le coût du framework.** Chaque processus PHP-FPM ouvre SA connexion
  PostgreSQL à chaque requête, là où le worker en réutilise une du pool. Ce coût pèse lourd dans le
  résultat. C'est une propriété du mode worker et non un défaut de réglage du monolithe — mais
  « on supprime le démarrage du framework » sous-estime ce qui se passe réellement.
- **Ces chiffres remplacent ceux publiés précédemment, et les contredisent dans les deux sens.** Le
  monolithe de démonstration d'alors était un stand-in synthétique, sans opcache et portant une
  attente simulée de 15 ms : environ cinq fois plus lent qu'un Symfony réel correctement réglé. Les
  écarts de latence annoncés (÷13.8) étaient flattés d'autant ; l'économie mémoire, elle, était
  sous-estimée. Le détail est dans [`BENCHMARK_RESULTS.md`](./BENCHMARK_RESULTS.md).
- **La fenêtre d'endurance de 3 h contient un recyclage de worker.** Elle établit l'absence de dérive
  sur la fenêtre, pas sur une durée de vie continue de worker de 3 h.
- **Le plafond de débit est mesuré sur une machine partagée.** Le générateur de charge tourne
  nativement sur l'hôte et les conteneurs sont bornés en CPU, mais ils se partagent le même
  silicium. Le banc refuse mécaniquement de publier toute marche où le générateur n'a pas tenu sa
  cadence — aucune ne l'a été ici — ce qui rend les chiffres exploitables sans les rendre absolus.

---

## 🏢 Positionnement — pour qui, et quand

**EcoShield s'adresse à une application PHP en production, rentable, que personne ne veut réécrire.**

**Pertinent si :**
- le trafic est soutenu (au-delà de ~20 requêtes simultanées en pointe) ;
- la facture d'hébergement est dimensionnée par les pics ;
- quelques routes concentrent l'essentiel du coût (recherche, catalogue, API mobile) ;
- une réécriture est exclue à court terme — pour des raisons de risque, de budget ou de calendrier.

**Peu pertinent si :**
- l'application est peu sollicitée : le worker résident coûterait plus qu'il n'économise ;
- le goulet est la base de données seule et le cache HTTP n'y change rien ;
- la réécriture est déjà lancée et financée.

**Trajectoire type d'un engagement :**

| Étape | Objet | Livrable |
|---|---|---|
| **1. Audit** | Mesurer l'existant, identifier les routes coûteuses | Rapport chiffré, courbe mémoire, routes candidates |
| **2. Pilote** | Passerelle en proxy + 1 à 2 routes reprises | Gain constaté en préproduction |
| **3. Déploiement** | Mise en production progressive, reprise route par route | Mesure de l'écart avant/après sur les routes reprises |
| **4. Exploitation** | Nouvelles reprises au fil du besoin | Dette contenue, pas éliminée — assumé |

---

## 🏗️ Architecture technique

| Brique | Choix | Raison |
|---|---|---|
| Moteur HTTP | **FrankenPHP** (Caddy) | Mode worker : le framework reste en mémoire entre les requêtes |
| Framework | **[Waffle-Commons](https://github.com/waffle-commons) `0.1.0-beta6`** | PHP 8.5 strict, sans dette, audité |
| Standards | **PSR-7 / PSR-15 / PSR-17 / PSR-18** | Aucun couplage propriétaire : le proxy accepte tout client conforme |
| Cache | **PSR-16** (Redis en production) | Partagé entre workers et entre instances |
| Qualité | **Mago** 0 erreur · **42 tests, 99.15 %** · **igor-php 0 KO** | Portes bloquantes, pas indicatives |

**Le code applicatif de la passerelle n'importe que des interfaces PSR.** Il ne dépend d'aucune
classe concrète du framework — pas même de ses contrats. C'est la démonstration recherchée : une
infrastructure réellement utile, écrite contre une surface publique.

---

## 🚀 Démarrage

### Pré-requis

- Docker et Docker Compose
- PHP 8.5 en CLI (développement local uniquement)

```bash
git clone https://github.com/waffle-commons/ecoshield-gateway.git
cd ecoshield-gateway

composer install
docker compose up -d
```

La pile démarre trois rôles : la passerelle (`:8099`), un monolithe legacy de démonstration
(Nginx + PHP-FPM, qui reconstruit son framework à chaque requête) et Redis.

**Seule la passerelle publie un port.** Le monolithe n'est joignable qu'à travers elle — un amont
accessible en direct se contourne, et le bouclier ne protège alors plus rien. Le défaut démarre
l'image de **production** : la première commande du README doit lancer ce qui est réellement
déployé, pas un mode développement dont les traces et les performances ne ressemblent à rien de
livrable.

```bash
curl localhost:8099/__ecoshield/health   # sonde : la PASSERELLE seule, pas l'amont
curl localhost:8099/api/products/42      # route reprise, servie par le worker
curl -i localhost:8099/api/catalogue     # proxyfiée + cache (en-tête X-EcoShield-Cache)
```

### Rejouer les mesures

Le banc s'exécute sur l'image de **production** — mesurer l'image de développement reviendrait à
mesurer un système de fichiers, ce que la première campagne a appris à ses dépens.

Les chiffres publiés viennent du harnais à **générateur natif** : k6 tourne sur l'hôte et non dans
un conteneur, et chaque conteneur est borné en CPU — sans quoi la VM Docker héberge à la fois les
sujets et le générateur, et la mesure décrit la contention de l'hôte plutôt qu'une architecture.

```bash
# Le monolithe Symfony du banc (application générée, non versionnée)
bench/legacy-symfony/bootstrap.sh

# La pile de mesure : conteneurs bornés, base amorcée, sonde mémoire ouverte
docker compose -f docker-compose.yml -f docker-compose.perf.yml \
               -f docker-compose.symfony.yml up -d --build

# Campagne complète : endurance + échelle + courbe mémoire + périmètre (~55 min)
bench/scripts/perf-run.sh full

# La campagne publiée (~3 h 50). `caffeinate` empêche la veille de l'hôte de
# couper la fenêtre — c'est ce qui avait invalidé la campagne de 3 h précédente.
DURATION=3h caffeinate -i bench/scripts/perf-run.sh full
```

k6 doit être installé **sur l'hôte** (`brew install k6`) : le lancer dans un conteneur sur macOS
ferait repasser la charge par la pile réseau de la VM, c'est-à-dire par le biais que ce harnais
corrige.

Le harnais historique (`./bench/ladder.sh`, `./bench/soak.sh`, k6 dans le réseau compose) reste en
place : il a produit [`bench/BENCH-RESULT.md`](./bench/BENCH-RESULT.md), et le conserver permet de
vérifier les erreurs de méthode qu'il a coûtées plutôt que de les réécrire.

Pour développer, la surcharge inverse monte les sources depuis l'hôte :

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

### Configuration

Toute la configuration passe par l'environnement (voir [`.env.example`](./.env.example)) :
`UPSTREAM_URL` (le monolithe à protéger), `CACHE_ADAPTER` et `REDIS_DSN`, `WORKER_COUNT`,
`MAX_REQUESTS`.

---

## 🗺️ État et périmètre

**Preuve de concept fonctionnelle**, exécutable et mesurée. Les trois piliers sont implémentés et
couverts par les tests.

**Volontairement absent, et documenté comme tel :** pooling de connexions vers l'amont, reprise sur
erreur et coupe-circuit, passthrough WebSocket, répartition sur plusieurs amonts, authentification de
bord. Ces briques relèvent du framework (`resilience-net`, beta7) plutôt que de la passerelle.

Le détail des choix — dont l'absence délibérée de garde SSRF sur le client amont — est dans
[`SECURITY.md`](./SECURITY.md).

---

## 📚 Documentation

| Document | Contenu |
|---|---|
| [`BENCHMARK_RESULTS.md`](./BENCHMARK_RESULTS.md) | **Campagne de référence** : endurance 3 h, échelle de débit, courbe mémoire, périmètre — avec les limites et les pièges de méthode |
| [`bench/BENCH-RESULT.md`](./bench/BENCH-RESULT.md) | Campagne beta6, antérieure — conservée pour la traçabilité des chiffres qu'elle a publiés et des erreurs qu'elle a consignées |
| [`SECURITY.md`](./SECURITY.md) | Posture de sécurité, signalement de vulnérabilité |
| [`CONTRIBUTING.md`](./CONTRIBUTING.md) | Portes de qualité, conventions, cycle de contribution |
| [`CHANGELOG.md`](./CHANGELOG.md) | Historique des versions |

---

## 📄 Licence

**MIT** — voir [`LICENSE`](./LICENSE). Librement auditable, modifiable et intégrable dans une
infrastructure d'entreprise propriétaire, sans obligation de publication des modifications.
