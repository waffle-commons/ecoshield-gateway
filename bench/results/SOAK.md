# Endurance — dérive mémoire

## Verdict

**Aucune fuite décelable.** Deux fenêtres indépendantes le montrent, et aucune des deux n'atteint la
durée visée : **la campagne de 3 h a échoué**, pour une raison extérieure à la passerelle.

| Fenêtre | Durée | Relevés | Pente passerelle | Borne (IC 95 %) | Statut |
|---|---:|---:|---:|---:|---|
| Soak complet | 0.50 h | 99 | **+0.23 MiB/h** | ±0.45 | ✅ complet et propre |
| Préfixe stationnaire du soak de 3 h | 1.16 h | 229 | **−0.08 MiB/h** | **±0.26** | ⚠️ préfixe d'une campagne échouée |
| Campagne de 3 h | — | — | — | — | ❌ **invalidée** |

Les deux pentes sont **compatibles avec zéro** : sur ces fenêtres, l'empreinte du worker résident est
plate au bruit de l'allocateur près. La borne la plus serrée obtenue est **±0.26 MiB/h sur 1.16 h**.

**Ce qui n'est donc PAS établi :** une fuite plus lente que ~0.26 MiB/h — soit ~6 MiB par jour —
resterait invisible. Pour un service censé tourner des semaines, cela reste à démontrer, et cela
demande une fenêtre de plusieurs heures sur une machine dédiée.

## Pourquoi la campagne de 3 h est invalidée

Le relevé a démarré à 03:18 sur une pile stabilisée et s'est déroulé normalement pendant environ
soixante-dix minutes, puis la machine hôte a décroché.

| Tranche | Moyenne passerelle | Intervalle d'échantillonnage |
|---|---:|---:|
| 0 → 70 min | 138.3 – 138.6 MiB | 18 – 19 s (cible : 15 s) |
| 70 → 90 min | 138.9 → 140.4 MiB | 19 s |
| 90 → 100 min | 139.9 MiB, minimum à 134.6 | 19 s |
| au-delà de 100 min | 31 – 60 MiB, instable | **30 – 94 s** |

L'ordre des symptômes désigne la cause. La mémoire des **deux** conteneurs s'effondre — passerelle
et PHP-FPM — ce qu'aucune fuite applicative ne produit. Le conteneur n'a jamais redémarré
(`RestartCount: 0`), donc le processus est resté le même. Et l'échantillonneur lui-même a fini par
ralentir, passant de 19 s à 94 s entre deux relevés : **c'est l'instrument qui décroche, pas la
mesure qui découvre quelque chose.** En fin de campagne, la passerelle répondait en 6.7 s là où elle
répond en 1.4 ms — un ordre de grandeur incompatible avec un problème de code.

Diagnostic : épuisement des ressources de la VM Docker, sur une machine qui hébergeait simultanément
le générateur de charge, quatre conteneurs et l'environnement de développement.

## Deux coupes écartées, et pourquoi

Chercher une « fenêtre propre » dans une campagne ratée invite à couper là où le résultat arrange.
Deux critères ont été essayés puis rejetés, et le détail est consigné parce qu'il conditionne la
confiance dans le chiffre publié :

1. **Couper au premier décrochage franc de la mémoire** — inopérant : la décrue s'est révélée
   progressive, jamais brutale entre deux relevés consécutifs.
2. **Couper à l'allongement de l'intervalle d'échantillonnage** (critère indépendant des valeurs
   mesurées, donc a priori le plus sain) — trop tardif : l'instrument n'a ralenti qu'à 05:32, plus
   d'une demi-heure après le début de l'effondrement. Cette coupe rendait une pente de
   −37.59 MiB/h, qui ne décrit que la décrue.

La fenêtre retenue s'arrête à **70 minutes**, dernier point où le profil par tranches de dix minutes
est manifestement stationnaire. Le choix porte sur la **forme de la série**, pas sur la pente
obtenue — celle-ci n'a été calculée qu'après.

## À refaire

Une campagne de 3 h sur une machine dédiée, sans autre charge, resserrerait la borne autour de
±0.05 MiB/h. C'est la seule façon de passer de « aucune fuite sur une heure » à « aucune fuite
supérieure à X sur une journée » — la distinction qui compte pour un service en production.

```bash
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d --build
SETTLE=120 DURATION=3h VUS=6 ./bench/soak.sh
```

## Données

| Fichier | Contenu |
|---|---|
| `soak-mem.csv` | Série brute de la campagne de 3 h, effondrement compris — conservée |
| `soak-mem-stationnaire.csv` | Les 70 premières minutes, préfixe exploitable |
| `soak-mem-30min.csv` · `SOAK-30min.md` | Campagne de 30 min, complète et propre |
| `SOAK-unsettled.md` | Première tentative, démarrée sans stabilisation |
