## Intention

<!-- Le problème résolu et le raisonnement, pas le résumé du diff : GitHub
     affiche déjà les fichiers modifiés. -->

Ferme #

## Type

- [ ] Correction d'anomalie
- [ ] Évolution
- [ ] Mesure / banc
- [ ] Documentation
- [ ] Outillage, CI

## Portes de qualité

Elles sont bloquantes. Une contribution qui ne les passe pas n'est pas fusionnée,
quelle que soit la qualité de l'idée.

- [ ] `composer mago` — **aucune sortie** (ni erreur, ni avertissement, ni note)
- [ ] `composer tests` — suite verte, couverture ≥ 95 %
- [ ] `composer igor` — **0 KO**

<!-- Correction native, jamais suppression : une alerte se résout par un type
     juste ou une refonte, pas par une annotation d'exclusion, un `mixed` ou une
     ligne de base. -->

## Invariants

Cocher ce qui a été vérifié, et **expliquer plus bas** ce qui ne l'est pas.

- [ ] **Mémoire constante** — aucun `(string) $body` introduit. Un seul
      transtypage convertit silencieusement le proxy en tampon, et rien dans le
      système de types ne proteste.
- [ ] **Aucun état entre requêtes** — les services restent résidents en mode
      worker ; un état résiduel est une fuite de données entre clients.
- [ ] **Cache fail-safe** — rien de privé n'est mutualisé : ni `Authorization`,
      ni `Cookie`, ni `Set-Cookie`, ni `no-store`/`private`.
- [ ] **Destination maîtrisée** — l'amont reste fixé par l'exploitant ; le client
      ne fournit que chemin et query.

## Mesures

<!-- Obligatoire pour tout changement touchant les performances. Un chiffre non
     mesuré n'est pas un chiffre. Joindre avant/après issus de bench/, et la
     borne de détection quand il s'agit d'endurance. -->

- [ ] Sans effet sur les performances
- [ ] Mesuré — résultats ci-dessous

```
# ./bench/ladder.sh (extrait pertinent)
```

## Documentation

- [ ] `README.md` — si un comportement visible change
- [ ] `SECURITY.md` — si une garantie, ou une limite, évolue
- [ ] `CHANGELOG.md` — sous la version non publiée
- [ ] `bench/BENCH-RESULT.md` — si un chiffre publié change

---

<!-- Faille de sécurité : ne pas ouvrir de pull request publique. Voir SECURITY.md. -->
