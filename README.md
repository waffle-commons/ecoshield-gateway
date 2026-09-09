# 🛡️ EcoShield Gateway

**Passerelle d'API FinOps pour monolithes PHP legacy.**
_Moderniser, sécuriser et réduire la facture d'hébergement — sans réécrire l'application._

[![Licence MIT](https://img.shields.io/badge/licence-MIT-green.svg)](./LICENSE)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777bb4.svg)](https://www.php.net/)
[![FrankenPHP](https://img.shields.io/badge/runtime-FrankenPHP%20worker-blue.svg)](https://frankenphp.dev/)
[![Qualité](https://img.shields.io/badge/mago-0%20erreur-success.svg)](./bench/BENCH-RESULT.md)
[![Couverture](https://img.shields.io/badge/couverture-99.15%25-success.svg)](./bench/BENCH-RESULT.md)

---

## 🎯 Le problème : le mur de la dette

Une application PHP traditionnelle (Nginx + PHP-FPM) **reconstruit son framework à chaque requête
HTTP**. Conteneur d'injection, routeur, configuration, autoloading : tout est rejoué, puis jeté.

Sous charge, la conséquence est financière avant d'être technique. La mémoire consommée croît avec le
nombre de requêtes simultanées — **environ 4 Mio par requête concurrente, mesurés** (§ Résultats) —
ce qui oblige à surdimensionner les serveurs pour absorber les pics : Black Friday, campagne
marketing, pic saisonnier. Le reste de l'année, cette capacité est payée sans être utilisée.

Les deux issues habituelles sont mauvaises :

| Option | Coût | Risque |
|---|---|---|
| Surdimensionner l'infrastructure | Récurrent, croissant | Aucun gain fonctionnel |
| Réécrire vers un langage asynchrone | 18 à 36 mois | Élevé — un projet de réécriture sur deux échoue |

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
                    │ 1. RESCUE  route reprise ──► réponse │  1.42 ms
                    │ 2. SHIELD  cache partagé  ──► réponse│  2.02 ms
                    │ 3. PROXY   tout le reste             │
                    └───────────────────┬──────────────────┘
                                        ▼
                            ┌───────────────────────┐
                            │  Monolithe legacy     │  19.62 ms
                            │  (Nginx + PHP-FPM)    │  intact, non modifié
                            └───────────────────────┘
```

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
conclure** — est dans **[`bench/BENCH-RESULT.md`](./bench/BENCH-RESULT.md)**.

| Indicateur | Mesure | Lecture |
|---|---|---|
| **Latence, route reprise** | **1.42 ms** contre 19.62 ms | **÷13.8** |
| **Latence, réponse en cache** | **2.02 ms** contre 22.81 ms | **÷11.3** |
| **Croissance mémoire** | **+0.18 Mio** par requête concurrente, contre **+4.14 Mio** pour PHP-FPM | **23× plus lente** |
| **Stabilité dans le temps** | pente mesurée sous le bruit de l'allocateur | empreinte plate |
| **Économie mémoire à 64 requêtes simultanées** | 141 Mio contre 280 Mio | **−49 %** |

### Ce que ces chiffres ne disent pas

Un banc qui ne publie que ses bons résultats n'est pas un banc. Trois réserves, toutes documentées :

- **En dessous d'une vingtaine de requêtes simultanées, la passerelle coûte PLUS cher** qu'un
  PHP-FPM au repos (130 Mio contre 19 Mio à une requête) : un worker résident garde le framework en
  mémoire en permanence. Le croisement se situe entre 16 et 32 requêtes concurrentes. **EcoShield
  est pertinent pour un trafic soutenu, pas pour une application peu sollicitée.**
- **Une économie de 80 % exigerait ~166 requêtes simultanées** (extrapolation linéaire de la pente
  mesurée). Le chiffre honnête à retenir est la pente, pas un pourcentage isolé.
- **Ce banc ne mesure pas la capacité.** Le générateur de charge partage ses 12 vCPU avec la
  passerelle et le monolithe : au-delà de 16 requêtes concurrentes, les chiffres décrivent la
  contention de l'hôte. Un chiffre de débit exige un générateur sur une machine séparée.

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
| **3. Déploiement** | Mise en production progressive, reprise route par route | Réduction de facture mesurée |
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

```bash
# La surcharge de mesure publie temporairement le monolithe : le banc a besoin
# d'un chemin de référence qui n'emprunte pas la passerelle.
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d --build

./bench/ladder.sh              # échelle de concurrence : latence et mémoire
DURATION=3h ./bench/soak.sh    # dérive mémoire dans le temps (Mio/h + borne de détection)
```

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
| [`bench/BENCH-RESULT.md`](./bench/BENCH-RESULT.md) | Protocole de mesure, résultats, limites, erreurs de méthode |
| [`SECURITY.md`](./SECURITY.md) | Posture de sécurité, signalement de vulnérabilité |
| [`CONTRIBUTING.md`](./CONTRIBUTING.md) | Portes de qualité, conventions, cycle de contribution |
| [`CHANGELOG.md`](./CHANGELOG.md) | Historique des versions |

---

## 📄 Licence

**MIT** — voir [`LICENSE`](./LICENSE). Librement auditable, modifiable et intégrable dans une
infrastructure d'entreprise propriétaire, sans obligation de publication des modifications.
