# Campagne d'endurance et de périmètre — EcoShield Gateway

> **Version mesurée :** `0.1.0` sur `waffle-commons/*` `0.1.0-beta6`.
> **Date :** 2026-09-09 · **Branche :** `perf/ecoshield-gateway-k6-soak`
> **Harnais :** `bench/k6/` + `bench/scripts/` (générateur k6 **natif sur l'hôte**).
> **Données brutes :** `bench/results/perf-*` · rapport généré : `bench/results/PERF-REPORT.md`.

Cette campagne complète `bench/BENCH-RESULT.md` sans le remplacer. Celui-ci
mesurait la passerelle avec k6 **dans** le réseau compose, et concluait lui-même
que c'était sa limite principale : au-delà de 16 VUs, la VM Docker héberge à la
fois les conteneurs et le générateur, si bien que l'échelle mesure la contention
de l'hôte plutôt qu'une architecture.

À défaut d'une seconde machine, la topologie retenue ici est la plus proche
disponible : **générateur natif sur l'hôte, conteneurs bornés en CPU, débit
fixé**. Ce que l'on gagne : une latence qui décrit la passerelle et non la file
d'attente de la VM. Ce que l'on ne gagne pas : un chiffre de capacité maximale,
qui reste hors de portée de ce banc et le restera tant que le générateur
partagera le silicium de son sujet.

---

## 1. Verdict

**PASS** sur les six critères mesurables de la campagne.

| Critère | Cible | Mesuré | Verdict |
|---|---|---|---|
| Débit soutenu | 300 req/s pendant 3 min | **300.00 req/s**, 54 001 requêtes | PASS |
| Erreurs HTTP | `rate < 0.001` | **0 échec sur 54 001** (0.000 %) | PASS |
| Latence passerelle | p95 < 15 ms · p99 < 30 ms | `rescue` **1.81 / 2.19 ms** · `shield` **2.15 / 2.57 ms** | PASS |
| Dérive mémoire worker | ≤ 256 kio nets | **−22.7 kio** sur le tas PHP | PASS |
| Périmètre (assertions négatives) | 100 % | **280 / 280**, 0 contournement | PASS |
| Cadrage des messages | aucun désync | **aucun** : 1 réponse par requête ambiguë | PASS |

Portes qualité, sur les fichiers touchés :

| Porte | Résultat |
|---|---|
| `composer mago` (fmt · lint · analyze · guard) | **sortie vide** — `No issues found` sur les quatre |
| `composer tests` | **45 tests, 143 assertions, OK** · couverture **99.19 %** |
| `composer igor` | **0 KO** · 1 WARN préexistant (voir §7) |

**Ce que ce verdict ne dit pas.** La fenêtre est de 3 minutes. Elle suffit à
écarter une fuite grossière et à valider le critère des 256 kio ; elle ne
certifie pas un service qui tourne des semaines. `BENCH-03` (beta6) soakait 3 h
par moteur pour cette raison précise, et c'est ce qui reste à faire ici (§4).

---

## 2. Périmètre de la mission, corrigé

Quatre exigences de la commande initiale ne correspondaient pas au code. Elles
sont consignées plutôt que contournées, parce qu'un banc qui rend vert sur une
surface inexistante est pire qu'un banc absent.

| Demandé | État réel | Traitement |
|---|---|---|
| Vérifier les assertions signées via `AuthBridgeVerifier` | **N'existe pas** — aucune occurrence dans le dépôt ni son `vendor/` | Retiré. Le contrat `UserAssertionInterface` existe bien dans `contracts`, mais la passerelle ne le consomme pas. |
| `GatewayAssertionMiddleware`, en-tête `X-Waffle-Assertion` | **N'existent pas** | Retirés. |
| 401 RFC 7807 sur assertion falsifiée | **Impossible** : rien ne vérifie d'assertion | Remplacé par un **constat mesuré** (§5) : un en-tête d'identité forgé traverse tel quel, parce qu'aucun composant ne lui accorde de valeur. |
| Rejet SSRF via `SsrfGuard` | **Volontairement absent** — `AppKernelFactory` documente ce choix : la destination est fixée par l'exploitant, et un guard SSRF rejetterait l'adressage interne qui est la raison d'être d'une passerelle | Remplacé par l'invariant réellement défendu : **le client ne choisit pas la destination** (§5). |

`AnonymousSecurityContext` documente l'authentification de bord comme un sujet
**beta7**. La passerelle est anonyme par conception ; c'est une propriété, pas un
manque, et le banc la mesure comme telle.

Deux écarts d'exécution, également assumés :

- **`num_threads 2` est invalide.** FrankenPHP exige un pool strictement
  supérieur au nombre de workers, les workers occupant des threads à demeure.
  Retenu : **4 threads / 2 workers**, épinglés dans la `Caddyfile` et **vérifiés
  via l'API d'administration de Caddy** plutôt que supposés depuis la variable
  d'environnement.
- **Le réglage `sysctl` de l'hôte n'a pas été appliqué.** Avec le Keep-Alive
  actif, le nombre de connexions est borné par `maxVUs` (60), pas par le nombre
  de requêtes. Mesuré après la charge : **36 sockets en `TIME_WAIT`**, contre les
  16 384 ports éphémères disponibles. Il n'y avait rien à corriger, et la
  commande demandait `sudo`.

Enfin, les livrables sont placés dans **`bench/`** et non `benchmarks/k6/` :
c'est la convention établie du dépôt, et le harnais historique y reste intact.

---

## 3. Topologie mesurée

Une latence sans la capacité déclarée des deux camps n'est pas reproductible.

| | |
|---|---|
| Hôte | MacBookPro15,1 — **Intel**, 6 cœurs physiques / 12 logiques, 16 Gio |
| Système | macOS 15.7.9 (Darwin 24.6.0) |
| Docker | 29.7.2 · VM 12 vCPU / 13.6 Gio |
| Générateur | k6 v2.0.0 (darwin/amd64), **natif sur l'hôte, jamais conteneurisé** |
| Passerelle | FrankenPHP 1.12.2, PHP 8.5.6 ZTS · **4 threads / 2 workers** · bornée **2 vCPU / 1 Gio** |
| Monolithe | `php:8.3-fpm-alpine` + Nginx · borné 2 vCPU / 1 Gio · `pm.max_children = 64` |
| Cache | Redis 7 · borné 0.5 vCPU |
| `MAX_REQUESTS` | **1 000 000** — voir §6, sans quoi la mesure d'endurance n'a aucun sens |

L'hôte est **Intel**, pas Apple Silicon comme la commande le supposait : les
12 vCPU de la VM sont 6 cœurs physiques hyperthreadés, que le générateur partage
avec ses sujets. Borner les conteneurs à 5 vCPU au total est ce qui rend la
mesure lisible malgré cela.

---

## 4. Débit et latence

**54 001 requêtes à 300.00 req/s soutenues, 0 échec.**

Latence en millisecondes. Les trois chemins ne mesurent pas la même chose, et
les mélanger dans un p95 global produirait un chiffre qui n'en décrit aucun :

| Chemin | requêtes | min | p50 | p90 | p95 | p99 | p99.9 | max |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `rescue` — servi par le worker | 32 401 | 0.72 | **1.23** | 1.64 | **1.81** | **2.19** | 4.16 | 20.15 |
| `shield` — servi du cache | 16 200 | 0.98 | **1.54** | 2.01 | **2.15** | **2.57** | 9.38 | 30.19 |
| `proxy` — traverse jusqu'au monolithe | 5 400 | 19.30 | 21.60 | 22.66 | 22.87 | 23.40 | 28.12 | 35.75 |

Le SLA (p95 < 15 ms, p99 < 30 ms) porte sur les deux premiers : ce sont les
chemins que la passerelle sert elle-même. Le troisième est borné par le
monolithe — 18 ms de bootstrap simulé par construction — et sa ligne est donnée
en enregistrement, jamais comme une promesse de la passerelle.

**Marge réelle : le p99 de `rescue` est à 2.19 ms pour un plafond à 30 ms**, soit
un facteur 13. Le chemin repris reste sous les 2 ms au 95ᵉ centile à 300 req/s
sur deux workers et deux cœurs.

**La queue de `shield` est plus lourde que celle de `rescue`** (p99.9 à 9.38 ms
contre 4.16) et c'est explicable, pas anormal : le TTL du Shield est de 30 s, et
à chaque expiration les requêtes concurrentes trouvent le cache froid en même
temps et repartent vers le monolithe. Sur une fenêtre de 3 minutes, six
expirations produisent quelques dizaines de requêtes lentes — assez pour peupler
le p99.9, pas assez pour peser sur le p99. Un exploitant qui voudrait lisser cela
implémenterait un rafraîchissement anticipé ; ce n'est pas nécessaire pour le
SLA visé.

---

## 5. Invariant mémoire

### 5.1 Deux grandeurs, deux résolutions

Le critère d'endurance s'exprime en **kio**. Le RSS du conteneur bouge par
mégaoctets, et `memory_get_usage(true)` par paliers de 2 Mio : ni l'un ni l'autre
ne peut trancher un seuil de 256 kio. C'est la raison d'être de la sonde
`/__ecoshield/memory` ajoutée par cette campagne, qui publie le tas PHP **à
l'octet** (fermée par défaut, ouverte par le seul banc).

### 5.2 Tas PHP du worker — 89 relevés sur 3.0 min

| Série | début (kio) | fin (kio) | Δ | pente (IC 95 %) | verdict |
|---|---:|---:|---:|---:|---|
| enveloppe haute | 888.10 | 888.10 | **+0.00** | −760 kio/h (±1310) | compatible avec zéro |
| enveloppe basse | 820.12 | 820.12 | **+0.00** | +0.00 kio/h (±0.00) | compatible avec zéro |
| pic cumulé | 1021.95 | 1021.95 | **+0.00** | +0.00 kio/h (±0.00) | compatible avec zéro |
| blocs réclamés à l'OS | 2.00 Mio | 2.00 Mio | **+0.00** | +0.00 Mio/h (±0.00) | compatible avec zéro |

**ΔM = −22.7 kio** (moyenne des 20 % finaux moins moyenne des 20 % initiaux, sur
l'enveloppe haute) — contre **256 kio** autorisés. **PASS.**

Le pic cumulé, qui est monotone par thread et ne redescend jamais, **n'a pas
bougé d'un octet** sur 54 001 requêtes. C'est le signal le plus net du tableau :
aucun worker n'a jamais eu besoin de plus de mémoire à la fin qu'au début.

*Note de méthode.* En mode worker FrankenPHP, chaque worker porte son propre tas
(PHP est compilé en ZTS). La série brute alterne donc entre les deux niveaux
selon le worker qui a servi le relevé, et une régression dessus mesure surtout
cette alternance : elle affiche ici une pente de −473 kio/h alors que le premier
et le dernier relevé sont **identiques**. Le verdict appartient aux enveloppes,
et l'analyseur refuse délibérément d'en rendre un sur la série brute.

### 5.3 RSS des conteneurs — 29 relevés sur 2.9 min

| Conteneur | début | fin | Δ | pente (IC 95 %) |
|---|---:|---:|---:|---:|
| `ecoshield-gateway` | 47.59 Mio | 48.01 Mio | **+0.42 Mio** | +8.13 Mio/h (±5.60) |
| `ecoshield-legacy-fpm` | 56.83 Mio | 56.81 Mio | **−0.02 Mio** | +2.57 Mio/h (±6.01) |

La pente de la passerelle sort de sa borne, et **cela ne démontre pas une
fuite** : sur 2.9 minutes, l'extrapolation horaire multiplie le bruit par 21. Le
chiffre solide est le Δ observé — **+0.42 Mio sur la fenêtre** — et sa lecture
honnête est la suivante : le tas PHP est plat à l'octet près, donc cette hausse
ne vient pas du code applicatif mais de ce qui l'entoure (arènes du runtime Go,
opcache qui finit de se remplir, tampons de Caddy). Une montée vers un palier et
une dérive lente sont indiscernables sur une telle fenêtre.

**C'est la limite principale de cette campagne, et elle est structurelle :** une
fenêtre de 3 h resserrerait la borne d'un facteur ~7. Le harnais est prêt
(`DURATION=3h bench/scripts/perf-run.sh soak`) ; seule la disponibilité de la
machine manque.

---

## 6. Périmètre — 280 assertions, 0 contournement

Chaque cas est rejoué **20 fois** : un refus « la plupart du temps » n'est pas un
refus.

| Assertion | passées | échouées |
|---|---:|---:|
| `Host` forgé → 400 | 20 | 0 |
| Traversée encodée `%2e%2e` → 400 | 20 | 0 |
| Traversée doublement encodée `%252e%252e` → 400 | 20 | 0 |
| Traversée par antislash `%2e%2e%5c` → 400 | 20 | 0 |
| Encodage trop profond (5 passes) → 400 | 20 | 0 |
| Cible protocole-relative `//evil.example` → 400 | 20 | 0 |
| Octet nul dans le chemin → 400 | 20 | 0 |
| **La sortie reste épinglée sur l'amont configuré** | 20 | 0 |
| `X-Forwarded-Host` forgé écrasé | 20 | 0 |
| Pair réel ajouté en fin de chaîne `X-Forwarded-For` | 20 | 0 |
| `Forwarded` et `X-Real-IP` supprimés | 20 | 0 |
| Requête porteuse de `Cookie` jamais servie du cache | 20 | 0 |
| Requête porteuse d'`Authorization` jamais servie du cache | 20 | 0 |
| En-tête d'identité arbitraire traversant tel quel *(constat)* | 20 | 0 |

**L'immunité SSRF, telle qu'elle existe réellement ici**, est l'épinglage de la
sortie : quoi qu'envoie le client — `X-Forwarded-Host: evil.example`,
`Forwarded`, `X-Real-IP`, cible protocole-relative — l'amont reçoit
systématiquement `Host: legacy-nginx`, et le miroir d'en-têtes du monolithe le
confirme requête après requête. Le client contrôle le chemin et la query, jamais
la destination.

### Cadrage des messages (socket brute)

k6 ne peut pas exprimer ces cas : le client HTTP de Go normalise le message avant
l'envoi. Ils passent donc par une socket brute, et ce qui est compté n'est pas un
code de statut mais **le nombre de réponses sorties d'une seule requête** — la
signature observable d'un désync.

| Cas | Réponses | Statut | Refusé par |
|---|---:|---:|---|
| CL.TE (requête imbriquée dans le corps) | 1 | 200 | — |
| TE.CL | 1 | 200 | — |
| Double `Content-Length` | 1 | 400 | **Caddy** (avant PHP) |
| Témoin (requête bien formée) | 1 | 200 | — |

**Aucun désync.** Le second message n'a jamais été servi.

**Constat à consigner :** le garde-fou `assertUnambiguousFraming()` de
`ProxyController` **ne se déclenche jamais dans cette topologie**. Le serveur HTTP
de Go résout l'ambiguïté avant PHP — il retire `Content-Length` dès qu'un
`Transfer-Encoding` est présent — si bien que la passerelle ne voit jamais les
deux en-têtes ensemble. L'invariant tient, mais il est tenu par Caddy, pas par le
code qui prétend le tenir. Les deux refus observés se distinguent d'ailleurs à
leur forme : la passerelle rend du `application/problem+json` (RFC 7807), Caddy du
`text/plain`.

---

## 7. Constats d'exploitation

Cinq observations qui ne font échouer aucune porte mais méritent d'être écrites.

1. **L'image de développement ne pouvait pas exécuter sa propre suite de tests.**
   `phpunit.xml` déclare un rapport clover ; sans pilote de couverture, PHPUnit
   12.5 ne se contente pas d'un avertissement — il charge les 45 tests, signale
   `No code coverage driver available`, et **sort en erreur sans en exécuter un
   seul**. Le message « No tests executed! » ressemblait à une suite vide.
   **Corrigé** : `pcov` ajouté à l'étage `dev` du `Dockerfile` (et à lui seul —
   l'étage `prod` ne doit embarquer aucun instrument).

2. **Les erreurs client attendues sont journalisées en `CRITICAL`, trace
   complète.** Chaque 400 de périmètre produit une pile de ~20 lignes. Sur ce
   banc, 140 rejets ont produit 140 traces. En production, un attaquant qui
   pilonne des chemins malformés fait grossir le journal à volonté : c'est une
   amplification de journalisation, et le niveau `CRITICAL` rend par ailleurs
   toute alerte sur ce seuil inutilisable. Un 400 de validation est un événement
   attendu, pas un incident.

3. **Un en-tête d'identité arbitraire traverse verbatim.** `X-Authenticated-User`,
   `X-Waffle-Assertion` ou tout équivalent atteint l'amont inchangé. C'est le
   comportement correct d'un proxy transparent dont l'amont possède sa propre
   authentification — la passerelle ne réécrit que la famille de transfert — mais
   c'est une **mise en garde d'exploitation** : un monolithe qui accorderait sa
   confiance à un tel en-tête « parce qu'il vient du réseau » ferait en réalité
   confiance au client.

4. **Avertissement PHP au démarrage, dans une dépendance.**
   `mkdir(): File exists in /app/vendor/waffle-commons/http/src/Factory/GlobalsFactory.php:91`
   à chaque démarrage de worker. Sans effet observé, mais bruyant. Le correctif
   appartient à `waffle-commons/http`, dépôt publié : signalé, non corrigé ici.

5. **La `Caddyfile` n'était pas au format canonique** (`caddy fmt` s'en plaignait
   à chaque démarrage). Reformatée en tabulations ; l'avertissement a disparu.

---

## 8. Pièges de méthode rencontrés

Consignés parce qu'ils ont failli produire des chiffres faux, et que les mêmes
attendent quiconque refera la mesure.

1. **Le Shield mettait les sondes de périmètre en cache.** Les premiers relevés
   d'épinglage de sortie rendaient tous la même réponse — `bootstrap_ms` identique
   au centième près, ce qui l'a trahi. On mesurait le cache, pas le traitement des
   en-têtes. Chaque requête de périmètre porte désormais une query unique.

2. **La première sonde de smuggling visait une route reprise.** `/api/products/42`
   est servie par le worker et n'atteint jamais `ProxyController` : le garde-fou
   testé n'était pas sur le chemin. Les sondes visent désormais une route
   proxyfiée.

3. **La préchauffe écrivait un résumé indiscernable d'une mesure.** En
   réutilisant le scénario de soak pour chauffer, `perf-soak.json` était écrit
   dès la chauffe ; un soak interrompu aurait laissé en place un résumé à
   100 req/s que le rapport aurait publié comme la campagne. Un scénario de
   préchauffe dédié (`bench/k6/lib/warmup.js`) n'écrit désormais rien.

4. **`MAX_REQUESTS` par défaut aurait vidé le soak de son sens.** Le défaut est
   2 000 ; 54 001 requêtes sur 2 workers, c'est 27 000 par worker, soit **treize
   recyclages** pendant la fenêtre, chacun remettant le tas à neuf. Une fuite
   aurait été rigoureusement invisible. Épinglé à 1 000 000 — le banc écosystème
   beta6 consigne exactement le même piège.

5. **Une régression sur la série brute du tas est trompeuse** en mode multi-worker
   (§5.2). D'où l'analyse par enveloppes.

---

## 9. Reproduire

```bash
# La pile de mesure — et RIEN D'AUTRE : le préambule refuse de mesurer
# si un conteneur étranger tourne.
docker compose -f docker-compose.yml -f docker-compose.perf.yml up -d --build

# Campagne complète (préchauffe → repos → soak → périmètre → analyse)
bench/scripts/perf-run.sh all

# Variantes
RATE=300 DURATION=3h bench/scripts/perf-run.sh soak     # fenêtre longue
REPEATS=50 bench/scripts/perf-run.sh perimeter
```

Prérequis : **k6 natif sur l'hôte** (`brew install k6`) — jamais dans un
conteneur sur macOS, sinon la charge repasse par la pile réseau de la VM et le
biais que cette campagne corrige revient intact.

Portes qualité (image `dev`, qui seule porte le pilote de couverture) :

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml run --rm --no-deps \
  --user "$(id -u):$(id -g)" -e HOME=/tmp --entrypoint sh gateway \
  -c 'git config --global --add safe.directory /app && cd /app && composer mago && composer tests && composer igor'
```

Les fichiers bruts de la campagne publiée sont conservés dans `bench/results/`
(`perf-*.csv`, `perf-*.json`, `perf-environment.json`), y compris l'instantané
complet de la topologie — c'est ce qui rend les chiffres ci-dessus vérifiables
plutôt que déclaratifs.
