# Campagne d'endurance et de périmètre — EcoShield Gateway

> **Version mesurée :** `0.1.1` sur `waffle-commons/*` `0.1.0-beta6`.
> **Date :** 2026-09-09 · **Branche :** `perf/ecoshield-gateway-k6-soak`
> **Campagne :** complète — endurance 3 h, échelle de débit, courbe mémoire, périmètre.
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
| Débit soutenu | 300 req/s pendant **3 h** | **299.99 req/s**, **3 239 963 requêtes** | PASS |
| Erreurs HTTP | `rate < 0.001` | **0 échec sur 3 239 963** (0.00000 %) | PASS |
| Latence passerelle | p95 < 15 ms · p99 < 30 ms | `rescue` **1.74 / 2.05 ms** · `shield` **2.17 / 2.57 ms** | PASS |
| Dérive mémoire worker | ≤ 256 kio nets | **−131.8 kio** sur le tas PHP | PASS |
| Dérive RSS conteneur | — | **+0.00 Mio/h (±0.03)** sur 3 h | PASS |
| Santé de l'instrument | cadence tenue | médiane 2.0 s, pire écart 3.0 s sur 5 308 relevés | PASS |
| Périmètre (assertions négatives) | 100 % | **280 / 280**, 0 contournement | PASS |
| Cadrage des messages | aucun désync | **aucun** : 1 réponse par requête ambiguë | PASS |

Portes qualité, sur les fichiers touchés :

| Porte | Résultat |
|---|---|
| `composer mago` (fmt · lint · analyze · guard) | **sortie vide** — `No issues found` sur les quatre |
| `composer tests` | **52 tests, 162 assertions, OK** · couverture **99.29 %** |
| `composer igor` | **0 KO** · 1 WARN préexistant (voir §7) |

**Ce que ce verdict ne dit pas.** La fenêtre de 3 h contient UN recyclage de
worker (§5.4) : elle établit l'absence de dérive sur la fenêtre, pas sur une
durée de vie continue de worker de 3 h. Et la borne obtenue — ±0.03 Mio/h —
écarte toute fuite supérieure à ~0.7 Mio/jour, ce qui suffit largement pour un
service exploité, sans pour autant certifier des mois de fonctionnement.

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

### 4.1 Endurance — 3 h à débit constant

**3 239 963 requêtes à 299.99 req/s soutenues, 0 échec.** Pas un arrondi : zéro
sur 3,24 millions.

| Chemin | requêtes | p50 | p95 | p99 | p99.9 | max |
|---|---:|---:|---:|---:|---:|---:|
| `rescue` — servi par le worker | 1 943 978 | 1.22 | **1.74** | **2.05** | 3.17 | 129.77 |
| `shield` — servi du cache | 971 989 | 1.51 | **2.17** | **2.57** | 4.56 | 128.69 |
| `proxy` — traverse jusqu'au monolithe | 323 996 | 4.54 | 5.76 | 6.91 | 9.97 | 146.72 |

Le SLA (p95 < 15 ms, p99 < 30 ms) porte sur les deux premiers : ce sont les
chemins que la passerelle sert elle-même. **La marge est d'un facteur 8,6 sur le
p95** et de 14,6 sur le p99.

Le chemin `proxy` est désormais à 4.54 ms de p50, contre 21.60 ms dans la
campagne courte : c'est le vrai Symfony qui remplace le `usleep(15 ms)` du
stand-in. L'ancien monolithe de démonstration était donc environ **cinq fois plus
lent qu'un Symfony réel correctement réglé**, et tout écart publié contre lui
était flatté d'autant.

### 4.2 Échelle de débit — le genou de chaque camp

Même requête, même base, même ligne. Modèle ouvert : le débit demandé est
maintenu, et c'est la latence qui encaisse.

| Débit visé | Passerelle atteint | p50 | Monolithe atteint | p50 | Lecture monolithe |
|---:|---:|---:|---:|---:|---|
| 50 | 50.01 | 4.43 | 48.84 | 16.79 | débit tenu |
| 100 | 100.01 | 3.82 | 97.69 | 19.90 | débit tenu |
| 200 | 200.01 | 2.36 | **111.18** | 3 557.92 | **saturé** |
| 400 | 400.01 | 2.08 | **111.02** | 7 204.77 | **saturé** |
| 800 | **800.00** | 2.59 | **115.57** | 14 326.25 | **saturé** |

**Le plafond du monolithe est de ~111 req/s.** Qu'on lui en demande 200, 400 ou
800, il en sert toujours 111 à 116 : le surplus devient file d'attente, et la
latence passe de 20 ms à 14 secondes. Aucun des deux camps n'a rendu la moindre
erreur HTTP, même saturé — PHP-FPM met en file, il ne refuse pas.

**Le plafond de la passerelle n'a pas été atteint par l'échelle** : 800 req/s
était la marche haute, tenue avec zéro itération perdue. La courbe mémoire (§4.3)
le situe plus loin, vers **1 165 req/s** sur 2 vCPU.

Aucune marche, d'aucun côté, n'a été écartée pour cause de générateur défaillant :
chaque ligne décrit bien son sujet.

### 4.3 Débit en fonction de la concurrence

| Concurrence | Passerelle | p50 | Monolithe | p50 | Rapport de débit |
|---:|---:|---:|---:|---:|---:|
| 8 | 1 178.8 | 6.53 | 124.7 | 81.34 | 9.5× |
| 16 | 1 152.5 | 13.65 | 118.1 | 111.63 | 9.8× |
| 32 | 1 155.5 | 27.32 | 109.1 | 293.38 | 10.6× |
| 64 | 1 119.1 | 57.17 | 110.1 | 582.82 | 10.2× |
| 128 | 1 104.8 | 114.32 | 109.1 | 1 177.99 | 10.1× |

La passerelle plafonne à **~1 165 req/s** dès 8 requêtes en vol : les 2 vCPU sont
saturés, et toute concurrence supplémentaire devient de la latence, pas du débit.
Le débit ne chute que de 6 % entre 8 et 128 requêtes simultanées — un plateau
stable, pas un effondrement.

*Contrôle de cohérence :* la latence double exactement avec la concurrence
(6.53 → 13.65 → 27.32 → 57.17 → 114.32 ms) à débit constant. C'est la loi de
Little qui sort des données : 128 ÷ 1 105 = 116 ms attendus contre 114.32
observés. Un banc qui ne vérifierait pas cela pourrait publier du bruit.

**Trois affirmations défendables :**

- à charge égale (100 req/s), **5,2× moins de latence** — 3.82 contre 19.90 ms ;
- **≥ 10× de débit** à concurrence égale, borne basse puisque la passerelle
  n'a jamais été poussée à son plafond par l'échelle ;
- la passerelle à 800 req/s (2.59 ms) reste **plus rapide que le monolithe à
  50 req/s** (16.79 ms) : seize fois le trafic, un sixième de la latence.

**Ce que cet écart mesure vraiment.** Pas seulement le coût du framework. Chaque
processus PHP-FPM ouvre SA connexion PostgreSQL à chaque requête, là où le worker
en réutilise une du pool. Ce coût de connexion pèse lourd dans l'écart, et c'est
une propriété architecturale du mode worker — pas quelque chose qu'un monolithe
mieux réglé rattraperait. Mais cela veut dire que « on a supprimé le démarrage »
sous-estime le résultat, et que « Symfony est lent » le lit de travers.

---


## 5. Invariant mémoire

### 5.1 Deux grandeurs, deux résolutions

Le critère d'endurance s'exprime en **kio**. Le RSS du conteneur bouge par
mégaoctets, et `memory_get_usage(true)` par paliers de 2 Mio : ni l'un ni l'autre
ne peut trancher un seuil de 256 kio. C'est la raison d'être de la sonde
`/__ecoshield/memory`, qui publie le tas PHP **à l'octet** (fermée par défaut,
ouverte par le seul banc).

### 5.2 Tas PHP du worker — 5 308 relevés sur 3.00 h

| Série | début (kio) | fin (kio) | Δ | pente (IC 95 %) |
|---|---:|---:|---:|---:|
| enveloppe haute | 967.06 | 835.22 | −131.84 | −59.03 kio/h (±25.59) |
| enveloppe basse | 835.22 | 494.09 | −341.12 | −134.25 kio/h (±66.30) |
| pic cumulé | 1 048.57 | 647.82 | −400.75 | −115.76 kio/h (±5.28) |
| blocs réclamés à l'OS | 2.00 Mio | 2.00 Mio | **+0.00** | +0.00 Mio/h (±0.00) |

**ΔM = −131.8 kio** contre 256 kio autorisés. **PASS.**

Les blocs réclamés à l'OS n'ont pas bougé d'un octet en trois heures et
3,24 millions de requêtes.

### 5.3 RSS des conteneurs — 1 782 relevés sur 3.00 h

| Conteneur | début | fin | Δ | pente (IC 95 %) |
|---|---:|---:|---:|---:|
| `ecoshield-gateway` | 51.20 Mio | 48.02 Mio | −3.18 Mio | **+0.00 Mio/h (±0.03)** |
| `ecoshield-legacy-fpm` | 18.86 Mio | 19.49 Mio | +0.63 Mio | +0.01 Mio/h (±0.02) |

**C'est le chiffre le plus solide de la campagne.** La borne de détection est de
**±0.03 Mio/h** — soit ~0.7 Mio par jour. La campagne courte ne pouvait offrir
que ±5.60 Mio/h : la fenêtre de 3 h resserre la borne d'un facteur ~190.

**L'instrument a tenu.** Cadence de relevé : médiane 2.0 s, pire écart 3.0 s sur
la totalité des trois heures. Le mode de panne qui avait invalidé la campagne de
3 h de beta6 — machine hôte décrochant, échantillonneur passant de 19 s à 94 s —
ne s'est PAS reproduit. Le contrôle de cadence est désormais dans le rapport, et
il aurait signalé la panne d'alors.

### 5.4 Un recyclage de worker dans la fenêtre — à ne pas gommer

Toutes les pentes du tas sont NÉGATIVES, et le pic cumulé DESCEND — ce qui est
impossible pour un thread donné, `memory_get_peak_usage()` étant monotone.

L'inspection des relevés donne exactement trois niveaux de pic distincts. Un
worker passe de 1 048.6 kio à 647.8 kio à **t = 0.98 h**, tandis que l'autre tient
1 041.1 kio pendant les trois heures. C'est un worker remplacé : aucun plantage,
aucune erreur fatale, aucune requête perdue — un recyclage propre, cohérent avec
l'atteinte de `MAX_REQUESTS` par le worker le plus sollicité. FrankenPHP n'a rien
journalisé, ce qui empêche de le CERTIFIER ; c'est l'explication la plus cohérente
avec les données, pas un fait vérifié.

**Conséquence sur la portée de l'affirmation.** Les pentes négatives sont un
artefact de cette remise à zéro, et non une mémoire qui se libère. La formulation
correcte est « aucune dérive sur une fenêtre de 3 h contenant un recyclage
transparent », et non « un worker a tenu 3 h sans dériver ». Le ΔM de −131.8 kio
reste sous le budget, mais il est mesuré à travers une discontinuité.

Pour une affirmation de durée de vie continue, `MAX_REQUESTS` doit dépasser le
total de la campagne (10 000 000 plutôt que 1 000 000). La valeur utilisée ici
est consignée dans `bench/results/perf-environment.json`.

### 5.5 L'empreinte en fonction de la concurrence

C'est l'expérience qui porte l'argument FinOps, et elle exige un modèle fermé :
ce qui décide du nombre d'enfants PHP-FPM vivants n'est pas le débit d'arrivée
mais le nombre de requêtes SIMULTANÉMENT en vol.

| Concurrence | Passerelle (RSS crête) | Monolithe (RSS crête) |
|---:|---:|---:|
| 8 | 84.79 Mio | 37.73 Mio |
| 16 | 84.81 Mio | 50.53 Mio |
| 32 | 84.88 Mio | 74.48 Mio |
| 64 | 84.78 Mio | 112.00 Mio |
| 128 | 85.00 Mio | 112.60 Mio *(plafond de processus)* |

- **Passerelle : +0.0015 Mio par requête concurrente** (c = 8 à 128). L'empreinte
  bouge de 0.21 Mio pour une concurrence multipliée par seize — plate au bruit près.
- **Monolithe : +1.3149 Mio par requête concurrente** (c = 8 à 64).

La marche c = 128 du monolithe est **exclue de la régression** : son RSS cesse de
croître alors que la concurrence double, parce que `pm.max_children = 64` empêche
d'ouvrir un enfant de plus. Ce palier n'est pas une empreinte qui se stabilise,
c'est une concurrence qui n'est plus servie. L'inclure ramènerait la pente à
+0.6156 Mio/requête et publierait un monolithe **deux fois plus sobre qu'il n'est**.

**Croisement à ≈ 44 requêtes concurrentes.** En dessous de ce seuil, la passerelle
consomme DAVANTAGE — elle garde le framework résident quand le monolithe ne garde
rien : 84.8 contre 37.7 Mio à 8 requêtes en vol, soit 2,2× plus. Au-delà, l'écart
se creuse indéfiniment.

C'est la seule forme d'affirmation mémoire que ce banc autorise, et elle rejoint
la conclusion de `bench/BENCH-RESULT.md` : **la pente est publiable, un facteur
plat ne l'est pas.** Un exploitant en tire la règle utile — le bouclier devient
rentable en mémoire à partir d'une quarantaine de requêtes simultanées, et pas
avant.

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
   12.5 ne se contente pas d'un avertissement — il charge toute la suite, signale
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

6. **k6 rapporte le débit d'une sous-métrique sur la durée TOTALE de
   l'exécution**, pas sur celle de la marche qui l'a produite. Une marche de
   2 min dans une échelle de 10 min voyait son débit divisé par cinq : le tableau
   annonçait « 10 req/s atteints » pour une marche visant 50, alors que les
   6 001 requêtes comptées sur 120 s font 50.01 req/s. Le chiffre est désormais
   recalculé à partir du NOMBRE de requêtes et de la durée d'une marche.

7. **Itérations perdues : deux causes opposées sous un seul compteur.** Le
   garde-fou écartait toute marche en ayant perdu — ce qui jetait le résultat le
   plus important du banc, la saturation du monolithe à 111 req/s. Un SUJET qui
   sature (latence en secondes, débit plafonné) est une mesure ; un GÉNÉRATEUR
   qui flanche (sujet rapide, débit non tenu) n'en est pas une. La latence les
   discrimine, et le rapport les nomme séparément.

8. **Le plafond `pm.max_children` déguise la courbe mémoire en plateau.** Le RSS
   du monolithe cesse de croître entre 64 et 128 requêtes concurrentes, non
   parce que son empreinte se stabilise mais parce qu'il ne peut plus ouvrir
   d'enfant. Régresser sur ces points ramenait la pente de +1.31 à +0.62 Mio par
   requête — publier un monolithe deux fois plus sobre qu'il n'est. Les marches
   plafonnées sont désormais exclues de la régression, et l'exclusion est écrite.

9. **Un palier n'est un plafond que si la courbe a d'abord grimpé.** Le premier
   correctif du point précédent traitait la courbe PLATE de la passerelle comme
   une courbe tronquée, et écartait quatre marches sur cinq en invoquant un
   `pm.max_children` que la passerelle ne possède pas — c'est-à-dire qu'il
   supprimait le résultat même de l'expérience. La détection ne s'applique
   maintenant qu'aux séries ayant crû d'au moins 5 % sur la plage.

---

## 9. Reproduire

```bash
# La pile de mesure — et RIEN D'AUTRE : le préambule refuse de mesurer
# si un conteneur étranger tourne.
docker compose -f docker-compose.yml -f docker-compose.perf.yml up -d --build

# Campagne COMPLÈTE : endurance + échelle + courbe mémoire + périmètre (~55 min)
bench/scripts/perf-run.sh full

# La campagne publiée ici (~3 h 50), sous caffeinate pour que la veille de
# l'hôte ne coupe pas la fenêtre — c'est ce qui avait tué la campagne beta6 :
DURATION=3h caffeinate -i bench/scripts/perf-run.sh full

# Le monolithe Symfony doit être en place, sinon le stand-in synthétique répond
bench/legacy-symfony/bootstrap.sh
docker compose -f docker-compose.yml -f docker-compose.perf.yml \
               -f docker-compose.symfony.yml up -d --build

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
