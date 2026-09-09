# Résultats de mesure — EcoShield Gateway

> **Version mesurée :** `0.1.0-beta6` sur `waffle-commons/*` `0.1.0-beta6` (Packagist).
> **Date :** 2026-09 · **Harnais :** `bench/ladder.sh`, `bench/soak.sh`, `bench/stress.js`.
> **Données brutes :** `bench/results/` (CSV et résumés k6 conservés tels quels).

Ce document existe parce que les chiffres annoncés dans le README étaient des
objectifs, pas des mesures. Deux d'entre eux ne survivent pas au banc tels quels.
La démarche suit celle de `BENCH-05` (beta6), qui avait déjà conclu qu'un facteur
RAM plat n'était pas défendable et qu'il fallait publier la **pente**.

---

## 1. Ce qui est mesuré, et contre quoi

| | Passerelle | Monolithe legacy |
|---|---|---|
| Exécution | FrankenPHP, **mode worker**, framework résident | Nginx + **PHP-FPM**, framework reconstruit à chaque requête |
| Image | cible `prod` (code dans l'image, opcache figé, préchargement) | `php:8.3-fpm-alpine` |
| Capacité | `WORKER_COUNT=16` | `pm = ondemand`, `pm.max_children = 64` |
| Charge applicative | identique des deux côtés | identique des deux côtés |

Les deux chemins servent **la même charge utile**. La seule variable est
l'architecture d'exécution — c'est la seule chose que le pattern Strangler Fig
change réellement.

### Deux erreurs de méthode corrigées en cours de route

Elles sont consignées parce qu'elles invalidaient des séries entières, et que
les mêmes pièges attendent quiconque refera la mesure :

1. **`pm.max_children = 5`** (défaut de l'image) plafonnait le legacy à cinq
   requêtes simultanées. Sa mémoire paraissait donc excellente *et* sa latence
   catastrophique : on mesurait une file d'attente. Corrigé en `ondemand` avec
   un plafond de 64 — la mémoire suit alors la concurrence, ce qui est
   précisément la courbe à mesurer.
2. **L'image de développement** (source montée en bind-mount, opcache revalidant
   chaque fichier) a servi aux premières séries. Sur macOS le montage passe par
   virtiofs : on mesurait un système de fichiers. Les chiffres ci-dessous
   viennent tous de l'image `prod`.

---

## 2. Latence — la promesse « ÷5 » est dépassée, à faible concurrence

p50, en millisecondes, par niveau de concurrence :

| VUs | Legacy direct | Route reprise | Gain | Shield (cache) | Gain |
|---:|---:|---:|---:|---:|---:|
| 1 | 19.62 | **1.42** | **13.8×** | **2.02** | **9.7×** |
| 4 | 20.99 | **3.24** | **6.5×** | **4.68** | **4.5×** |
| 16 | 19.76 | 25.17 | 0.8× | 29.08 | 0.7× |
| 32 | 24.22 | 72.41 | 0.3× | 66.13 | 0.4× |
| 64 | 34.85 | 141.51 | 0.2× | 152.75 | 0.2× |

**À faible concurrence, la mesure dépasse l'objectif** : 13.8× sur une route
reprise là où le README annonce 5×. C'est le coût du démarrage qui disparaît —
le framework est déjà en mémoire quand la requête arrive.

**Au-delà de 16 VUs, la passerelle est plus lente, et ces lignes ne sont pas
publiables comme des chiffres de capacité.** Le banc tourne sur une VM Docker de
**12 vCPU** qui héberge simultanément le générateur de charge (k6), les 16
workers, jusqu'à 64 enfants PHP-FPM, Nginx et Redis. À 16 VUs et au-delà, ce qui
est mesuré est la contention de l'hôte, pas une architecture. Un chiffre de
capacité honnête exige un générateur de charge sur une machine distincte ; c'est
la limite principale de ce harnais, et elle est structurelle, pas accidentelle.

Ce que ces lignes établissent tout de même : la passerelle **n'a pas** de
supériorité intrinsèque en débit. Son intérêt est la latence par requête et
l'empreinte mémoire, pas la capacité brute.

---

## 3. Mémoire — la vraie conclusion, et la correction du README

Pic RSS observé pendant la charge (MiB) :

| VUs | Passerelle | Legacy FPM |
|---:|---:|---:|
| 1 | 129.8 | 18.7 |
| 4 | 131.1 | 27.3 |
| 16 | 133.6 | 82.5 |
| 32 | 139.0 | 153.6 |
| 64 | 141.2 | 279.6 |

**Pente par requête concurrente :**

- Passerelle : **+0.181 MiB**
- Legacy FPM : **+4.141 MiB**
- → la passerelle croît **22.9× moins vite**

C'est le résultat défendable, et c'est exactement la forme de conclusion à
laquelle `BENCH-05` était arrivé pour le framework lui-même.

### Ce que le README annonçait, et ce qu'il faut lire à la place

> « 📉 Réduction de la RAM : ~80% d'économie de mémoire sous haute charge. »

**Faux comme chiffre général, et trompeur sans qualificatif de charge.**

- À **1 requête concurrente**, la passerelle consomme **7× plus** que le legacy
  (129.8 contre 18.7 MiB) : elle garde le framework résident, le legacy ne garde
  rien.
- Le **croisement** se situe entre **16 et 32 requêtes concurrentes**.
- À **64 concurrentes**, l'économie réelle est de **49 %** (141.2 contre 279.6).
- Les **80 %** annoncés exigeraient environ **166 requêtes concurrentes**
  (extrapolation linéaire de la pente mesurée), et 50 % en exigeraient ~64.

Formulation défendable : *« à empreinte constante quelle que soit la charge, là
où PHP-FPM paie ~4 Mio par requête concurrente — soit une croissance 23× plus
lente, et une économie qui devient réelle au-delà d'une vingtaine de requêtes
simultanées. »*

C'est une meilleure promesse que celle d'origine, parce qu'elle est vraie et
qu'elle indique à un exploitant **à partir de quand** le bouclier est rentable.

---

## 4. Le Shield — l'effet réel du cache

Mesuré à faible concurrence, hors contention :

| Chemin | p50 | Monolithe touché ? |
|---|---:|---|
| Proxy, cache neutralisé | 22.81 ms | à chaque requête |
| Shield, mutualisable | **2.02 ms** | une fois par TTL |

**11.3× plus rapide**, et surtout : la charge cesse d'atteindre le monolithe.
C'est le pilier qui décharge réellement la base de données, et le seul dont le
bénéfice grandit avec le trafic plutôt que de s'éroder.

Un proxy pur coûte **1.16×** la latence directe (22.81 contre 19.62 ms au repos) :
c'est le prix du saut supplémentaire, et il est le prix à payer pour pouvoir
intercepter et mettre en cache.

---

## 5. Endurance — la promesse qui ne se voit pas sur une rafale

L'échelle prouve que l'empreinte ne suit pas la concurrence. Elle ne dit rien de
la durée : un worker qui fuit 1 Mio/h est irréprochable pendant dix minutes et
mort au bout d'une semaine. C'est la raison pour laquelle `BENCH-03` (beta6) a
soaké **3 h par moteur** au lieu de se fier à une rafale.

Le verdict n'est pas « la mémoire a-t-elle bougé » — elle bouge toujours, au gré
de l'allocateur — mais **la pente** d'une régression linéaire, en Mio/h, et la
borne en dessous de laquelle une fuite resterait invisible sur la fenêtre.

**Résultat — aucune fuite décelable, sur deux fenêtres indépendantes.**

| Fenêtre | Durée | Pente passerelle | Borne (IC 95 %) |
|---|---:|---:|---:|
| Soak complet | 0.50 h | **+0.23 MiB/h** | ±0.45 |
| Préfixe stationnaire du soak de 3 h | 1.16 h | **−0.08 MiB/h** | **±0.26** |

Les deux pentes sont **compatibles avec zéro** : l'empreinte du worker résident
est plate dans le temps comme elle l'est en concurrence. La borne la plus serrée
obtenue est **±0.26 Mio/h sur 1.16 h**.

**La campagne de 3 h a échoué**, pour une raison extérieure à la passerelle : la
machine hôte a décroché après environ soixante-dix minutes. La mémoire des DEUX
conteneurs s'est effondrée — ce qu'aucune fuite applicative ne produit — sans que
le conteneur redémarre, et l'échantillonneur lui-même a ralenti de 19 s à 94 s
entre deux relevés. En fin de campagne la passerelle répondait en 6.7 s au lieu
de 1.4 ms. C'est l'instrument qui décroche, pas la mesure qui découvre quelque
chose.

*Portée de l'affirmation :* une fuite plus lente que **~0.26 Mio/h** — environ
6 Mio par jour — resterait invisible sur ces fenêtres. Suffisant pour écarter une
fuite grossière, insuffisant pour certifier un service qui tourne des semaines.
Une campagne de 3 h sur une machine dédiée resserrerait la borne autour de
±0.05 Mio/h ; c'est ce que `BENCH-03` (beta6) a fait, et c'est ce qui reste à
faire ici. Détail complet, dont les deux critères de coupe écartés :
`bench/results/SOAK.md`.

### Un premier soak a été jeté, et pourquoi

La première fenêtre a rendu une pente de **−19.44 Mio/h** — mémoire en baisse.
Deux enseignements, tous deux consignés plutôt que corrigés en silence :

1. **Le relevé avait démarré sur le pic laissé par l'échelle à 64 VUs** (150.4
   Mio) et enregistrait la décrue vers l'état stable (138.5 Mio). Il mesurait un
   retour au calme, pas une dérive. `soak.sh` impose désormais une phase de
   repos avant le premier relevé.
2. **Le script d'analyse traitait `|pente|` comme un défaut** et signalait donc
   une mémoire qui *descend* comme une anomalie — en affichant au passage un
   « +-467 Mio par jour » absurde. Une fuite est une pente **positive** ; une
   pente négative veut dire que l'allocateur rend. Corrigé dans `drift.py`.

Le premier relevé est conservé (`bench/results/SOAK-unsettled.md`) : un banc dont
on ne garde que les séries flatteuses ne prouve rien.

---

## 6. Saturation — ce que le genou vaut ici

`bench/stress.js` monte la charge par paliers jusqu'à la rupture, puis
redescend : une passerelle saine **récupère**, et une latence qui reste haute
après la pointe signale une file qui ne se vide pas plutôt qu'une saturation
passagère.

Sur ce banc, l'échelle situe déjà le genou entre **4 et 16 requêtes
concurrentes** — mais ce chiffre décrit la VM, pas la passerelle : le générateur
de charge, les 16 workers, jusqu'à 64 enfants PHP-FPM, Nginx et Redis se
partagent 12 vCPU. Un genou exploitable exige un générateur sur une machine
séparée. Le harnais est prêt ; la mesure ne l'est pas tant que la topologie ne
l'est pas.

---

## 7. Reproduire

```bash
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d --build

./bench/ladder.sh                       # échelle de concurrence (latence + mémoire)
DURATION=30m ./bench/soak.sh            # dérive mémoire
docker run --rm --network ecoshield-gateway_default -e SCENARIO=rescue \
  -v "$PWD/bench:/bench" grafana/k6:latest run /bench/stress.js   # genou de saturation
```

Les résultats bruts de la campagne ci-dessus sont conservés dans
`bench/results/` — y compris les séries invalidées (`ladder-4workers.csv`,
`ladder-dev-image.csv`), gardées pour que les erreurs de méthode restent
vérifiables plutôt que réécrites.
