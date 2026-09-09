# Journal des modifications — EcoShield Gateway

Toutes les évolutions notables de ce projet sont consignées ici.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet applique le
[versionnage sémantique](https://semver.org/lang/fr/).

EcoShield est une **application autonome**, versionnée et publiée indépendamment du framework
qu'elle consomme. Son numéro de version décrit **la passerelle et rien d'autre** : il ne recopie pas
celui de `waffle-commons`, parce qu'une version doit parler de l'artefact qu'elle étiquette. Une
publication du framework ne provoque donc pas de version ici, et réciproquement.

La version du framework sur laquelle chaque release a été construite et vérifiée est indiquée dans
l'entrée correspondante, et fait foi dans `composer.json`.

La trajectoire suit la maturité de la passerelle, pas le calendrier du framework :

| Version | Étape | Framework de référence |
|---|---|---|
| `0.1.0` | Preuve de concept | `0.1.0-beta6` |
| `0.2.0` | Alpha — bac de test Strangler Fig | `0.1.0-beta7` |
| `0.3.0` | Bêta — campagne FinOps complète | `0.1.0-beta8` |
| `1.0.0` | Production, après la période de soak | `1.0.0` |

---

## [0.1.0] — 2026-09

_Construite et vérifiée sur `waffle-commons` **`0.1.0-beta6`**, installé depuis Packagist._

Première version publiée en tant qu'**application déployable**. Les itérations précédentes ne
livraient qu'une classe de proxy inverse ; celle-ci est la passerelle décrite par le README — une
pile que l'on démarre avec `docker compose up -d` et que l'on place devant un monolithe.

### Ajouté — les trois piliers

- **Pilier 1 — Interception (Rescue).** `RescueController` sert les routes reprises depuis le worker
  résident, sans jamais atteindre le monolithe. `/__ecoshield/health` rend compte de **la passerelle
  seule** : une sonde qui échouerait parce que l'amont est tombé conduirait un orchestrateur à
  redémarrer le seul composant encore capable de servir du cache.
- **Pilier 2 — Proxy transparent.** `GatewayController` est une route attrape-tout de priorité
  `-1000` : toute route native, présente ou future, est préférée sans configuration. Reprendre une
  route consiste à en ajouter une ; le routage du legacy et les URL vues par les clients ne changent
  pas. C'est ce qui rend la migration incrémentale plutôt qu'un basculement.
- **Pilier 3 — Bouclier (Shield).** `ResponseCache` mutualise les réponses de l'amont via PSR-16
  (Redis en production). Il **refuse bien plus qu'il n'accepte**, délibérément : méthodes autres que
  `GET`/`HEAD`, requêtes portant `Authorization` ou `Cookie`, réponses autres que `200` ou portant
  `Set-Cookie`, `no-store` ou `private`. Servir la réponse privée d'un client à un autre est la
  fuite classique des caches de passerelle : elle coûte moins cher à empêcher qu'à détecter.

### Ajouté — l'application

- Point d'entrée FrankenPHP en mode worker, fabrique de kernel, configuration YAML, préchargement
  OPcache, `.env.example`.
- `docker compose up -d` monte l'ensemble de la démonstration : la passerelle, un monolithe legacy
  derrière Nginx + PHP-FPM qui reconstruit son framework à chaque requête, et Redis.
- Banc de mesure versionné : `ladder.sh` (échelle de concurrence), `soak.sh` + `drift.py`
  (endurance), `stress.js` (genou de saturation), `report.py`.
- Documentation autonome : politique de sécurité, guide de contribution et code de conduite propres
  au dépôt, en français — les redirections vers le framework ont été remplacées par du contenu réel.

### Mesuré

Chiffres issus du banc, rejouables. Protocole complet et **limites** dans
[`bench/BENCH-RESULT.md`](bench/BENCH-RESULT.md).

- **Latence : ÷13.8 sur une route reprise** (1.42 ms contre 19.62 ms) et **÷11.3 sur une réponse en
  cache**. Le README annonçait ÷5 ; la mesure dépasse l'objectif.
- **Mémoire : +0.18 Mio par requête concurrente**, contre **+4.14 Mio** pour PHP-FPM — une croissance
  **23× plus lente**.
- **Endurance :** pente sous le bruit de l'allocateur sur une charge mixte, avec borne de détection
  publiée à 95 %.

### Corrigé — deux annonces qui n'ont pas survécu à la mesure

- **« ~80 % d'économie de RAM » est faux comme chiffre général.** Sous le croisement (16 à 32
  requêtes simultanées), un worker résident coûte **plus** cher qu'un PHP-FPM au repos : 129.8 Mio
  contre 18.7 à une requête concurrente. L'économie réelle est de **49 % à 64 requêtes simultanées**,
  et 80 % en exigeraient environ 166. L'affirmation défendable est la **pente**, ce qui est aussi la
  conclusion à laquelle `BENCH-05` était parvenu pour le framework lui-même en beta6.
- **Le README annonçait un protocole de mesure « à venir ».** Il est livré, exécuté et publié.

### Consigné — trois erreurs de méthode

Conservées plutôt que corrigées en silence : chacune a invalidé une campagne entière, et les mêmes
pièges attendent quiconque refera la mesure.

- Le défaut de l'image, `pm.max_children = 5`, plafonnait le monolithe à cinq requêtes simultanées :
  sa mémoire paraissait excellente et sa latence catastrophique. On mesurait une file d'attente.
- Les premières séries ont tourné sur l'image de développement — sources montées, opcache revalidant
  chaque fichier — ce qui, sur macOS, mesure virtiofs et non PHP.
- Le premier relevé d'endurance a rendu une pente négative que `drift.py` a signalée comme un défaut.
  Deux bugs à la fois : la fenêtre démarrait sur le pic laissé par la campagne précédente, et
  l'analyse traitait `|pente|` comme une anomalie — alors qu'une fuite est une pente **positive**.

Les séries invalidées restent dans `bench/results/`.

### Limite assumée

**Ce banc ne mesure pas la capacité.** Le générateur de charge partage les 12 vCPU de la machine avec
la passerelle et le monolithe : au-delà de 16 requêtes simultanées, les chiffres décrivent la
contention de l'hôte. Quadrupler le nombre de workers n'a d'ailleurs changé le débit que de moins de
5 %, ce qui établit que le plafond n'a jamais été le nombre de workers. Un chiffre de débit exige un
générateur sur une machine distincte.

### Périmètre non couvert

Pooling de connexions vers l'amont, reprise sur erreur et coupe-circuit, passthrough WebSocket,
répartition sur plusieurs amonts, authentification de bord. Ces briques relèvent du framework
(`resilience-net`, beta7) plutôt que de la passerelle.

### Portes de qualité

`composer mago` sans aucune sortie · **42 tests, 99.15 %** de couverture d'instructions ·
`igor-php` **0 KO** (10 services sur 10 sans état) · `composer validate --strict` conforme.

---

## Versions antérieures

Les itérations `poc/*` antérieures ne publiaient qu'une bibliothèque contenant le contrôleur de
proxy. Elles n'ont pas fait l'objet d'une publication et ne sont pas documentées ici.
