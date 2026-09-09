# Contribuer à EcoShield Gateway

Merci de l'intérêt porté au projet. Ce document décrit ce qui est attendu d'une contribution et,
surtout, **pourquoi** : les règles qui suivent ne sont pas des préférences de style, elles protègent
des propriétés que le projet revendique publiquement.

---

## 🧭 Principes

**1. Une passerelle se juge sur ce qu'elle refuse.**
Les correctifs qui ajoutent une garde sont les bienvenus ; ceux qui en assouplissent une doivent
expliquer quelle propriété est abandonnée et pourquoi c'est acceptable.

**2. Un chiffre non mesuré n'est pas un chiffre.**
Toute affirmation de performance doit être reproductible par `bench/`. Le projet a déjà corrigé deux
de ses propres annonces après mesure — c'est la norme attendue, pas une exception.

**3. La mémoire constante est un invariant, pas un objectif.**
Le corps des messages traverse la passerelle en flux. **Ne jamais faire `(string) $body`** : un seul
transtypage convertit silencieusement un proxy en tampon, et rien dans le système de types ne
proteste. Un test épingle l'identité du flux transmis à l'amont pour cette raison précise.

**4. L'application n'importe que des interfaces PSR.**
C'est la thèse du projet : une infrastructure utile écrite contre une surface publique. Introduire
une dépendance à une classe concrète du framework demande une justification explicite.

---

## 🚦 Portes de qualité

Elles sont **bloquantes**. Une contribution qui ne les passe pas ne sera pas fusionnée, quelle que
soit la qualité de l'idée.

```bash
composer mago     # format, lint, analyse statique, périmètre de dépendances
composer tests    # PHPUnit
composer igor     # sûreté en mode worker
```

| Porte | Exigence | Raison |
|---|---|---|
| `mago` | **Zéro sortie** — pas d'erreur, pas d'avertissement, pas de note | Une ligne de base rend une dette invisible ; le projet n'en tolère aucune |
| `tests` | Suite verte, couverture **≥ 95 %** | Actuellement 99.15 % : la régression se verrait |
| `igor` | **0 KO** | Un état résiduel en mode worker est une fuite de données entre clients |

**Correction native, jamais suppression.** Une alerte de l'analyseur se résout par un type juste ou
une refonte — pas par une annotation d'exclusion, un `mixed` ou une ligne de base. La seule exception
tolérée du dépôt (la frontière de désérialisation du cache) est commentée, portée à un unique
fichier, et la valeur y est intégralement revérifiée.

---

## 🛠️ Mise en route

```bash
git clone https://github.com/waffle-commons/ecoshield-gateway.git
cd ecoshield-gateway
composer install

# Surcharge de développement : sources montées depuis l'hôte, édition immédiate.
# Le défaut, lui, démarre l'image de production — c'est ce qu'un utilisateur lance.
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

Vérifier que la pile répond :

```bash
curl localhost:8099/__ecoshield/health
curl localhost:8099/api/products/42
```

**Pour mesurer, ne jamais utiliser la surcharge de développement** : elle monte les sources depuis
l'hôte et fait revalider chaque fichier par l'opcache, ce qui mesure le système de fichiers plutôt
que PHP. La surcharge de mesure part du défaut (production) et publie en plus le monolithe, pour
disposer d'un chemin de référence.

```bash
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d --build
./bench/ladder.sh
```

---

## 📐 Conventions de code

Le projet suit les standards du framework Waffle-Commons :

- `declare(strict_types=1);` en première instruction de chaque fichier ;
- **pas de `mixed`** : le type se résout, il ne s'élargit pas ;
- `#[\Override]` sur toute méthode qui implémente ou redéfinit ;
- constantes typées, visibilité asymétrique et hooks de propriété PHP 8.5 ;
- exceptions de domaine explicites, jamais de `@` ni de capture générique ;
- **le code, les identifiants et les messages techniques sont en anglais** ; la documentation de ce
  dépôt est en **français**, y compris les commentaires, parce que son public l'est.

### Commentaires

Un commentaire explique **pourquoi**, jamais **quoi**. Le code dit déjà ce qu'il fait. Les
commentaires les plus utiles du dépôt décrivent des pièges — un cadrage ambigu, un `(string)` fatal,
un défaut de configuration qui fausse une mesure.

---

## 🔀 Cycle de contribution

1. **Ouvrir une issue avant un changement conséquent.** Un correctif d'une ligne peut aller
   directement en pull request ; une évolution d'architecture mérite une discussion préalable.
2. **Une pull request = un sujet.** Un correctif, une amélioration de mesure, une reprise de route —
   pas les trois.
3. **Décrire l'intention.** Le message de commit explique le problème et le raisonnement, pas
   seulement le diff.
4. **Joindre les preuves.** Un changement de performance sans mesure `bench/` ne peut pas être
   évalué.
5. **Les portes doivent être vertes** avant la revue.

### Signaler une faille

**Ne pas ouvrir d'issue publique.** Voir [`SECURITY.md`](./SECURITY.md).

---

## 🧪 Contribuer une mesure

Le banc fait partie du produit : c'est ce qui rend les annonces du README vérifiables.

| Fichier | Rôle |
|---|---|
| `bench/ladder.sh` | Échelle de concurrence — latence et mémoire par palier |
| `bench/soak.sh` + `bench/drift.py` | Endurance — pente en Mio/h et borne de détection à 95 % |
| `bench/stress.js` | Genou de saturation |
| `bench/BENCH-RESULT.md` | Protocole, résultats et **limites** |

Trois règles :

- **Conserver les séries invalidées.** Le dépôt garde ses campagnes erronées (`ladder-4workers.csv`,
  `ladder-dev-image.csv`, `SOAK-unsettled.md`) : un banc dont on ne garde que les résultats flatteurs
  ne prouve rien.
- **Publier la borne, pas seulement la valeur.** « Aucune fuite » ne veut rien dire sans la fenêtre
  d'observation ; « +0.23 ± 0.45 Mio/h sur 30 minutes » en veut.
- **Documenter ce que la mesure ne permet pas de conclure.** La section correspondante du rapport est
  aussi importante que les résultats.

---

## 📜 Cadre

En contribuant, vous acceptez que votre travail soit publié sous licence [MIT](./LICENSE) et vous
vous engagez à respecter le [code de conduite](./CODE_OF_CONDUCT.md).
