# Endurance — dérive mémoire

Durée observée : **0.50 h** · 99 relevés

| Conteneur | Départ (MiB) | Fin (MiB) | Δ (MiB) | Pente (MiB/h) |
|---|---|---|---|---|
| `ecoshield-gateway` | 138.1 | 138.4 | +0.3 | +0.23 |
| `ecoshield-legacy-fpm` | 40.9 | 39.6 | -1.3 | +0.05 |

Pente de la passerelle : **+0.23 MiB/h**, sous le bruit de l'allocateur — aucune fuite décelable sur cette fenêtre.

> Portée : sur 0.50 h, une fuite plus lente que ~0.5 MiB/h resterait indétectable. Seul l'allongement de la fenêtre resserre cette borne — beta6 a soaké 3 h par moteur pour la descendre à ~1.7 MiB/h.

