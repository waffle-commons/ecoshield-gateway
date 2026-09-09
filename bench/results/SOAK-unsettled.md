# Endurance — dérive mémoire

Durée observée : **0.24 h** · 49 relevés

| Conteneur | Départ (MiB) | Fin (MiB) | Δ (MiB) | Pente (MiB/h) |
|---|---|---|---|---|
| `ecoshield-gateway` | 150.4 | 138.5 | -11.9 | -19.44 |
| `ecoshield-legacy-fpm` | 78.2 | 82.7 | +4.4 | +27.75 |

Pente de la passerelle : **-19.44 MiB/h** — significative. À ce rythme, +-467 MiB par jour : à instruire avant toute mise en production.

> Portée : une fuite plus lente que ~19.4 MiB/h resterait indétectable sur 0.24 h. Allonger la fenêtre resserre cette borne — c'est la seule façon de la resserrer.

