#!/usr/bin/env python3
"""Dérive mémoire : la pente, pas l'écart entre deux instantanés.

Un test d'endurance ne se juge pas sur « la mémoire a-t-elle bougé » — elle
bouge toujours un peu, au gré de l'allocateur et du GC. Il se juge sur la PENTE
d'une régression linéaire, et sur la borne en dessous de laquelle une fuite
resterait indétectable pour la durée observée.
"""
from __future__ import annotations

import pathlib
import sys


def slope(points: list[tuple[float, float]]) -> tuple[float, float]:
    """Moindres carrés : rend (pente par seconde, ordonnée à l'origine)."""
    n = len(points)
    if n < 2:
        return 0.0, points[0][1] if points else 0.0
    mx = sum(p[0] for p in points) / n
    my = sum(p[1] for p in points) / n
    denom = sum((p[0] - mx) ** 2 for p in points)
    if denom == 0:
        return 0.0, my
    m = sum((p[0] - mx) * (p[1] - my) for p in points) / denom
    return m, my - m * mx


def main(path: str) -> int:
    lines = pathlib.Path(path).read_text().splitlines()[1:]
    gw: list[tuple[float, float]] = []
    fpm: list[tuple[float, float]] = []
    for line in lines:
        parts = line.split(";")
        if len(parts) != 3:
            continue
        try:
            t, g, f = float(parts[0]), float(parts[1]), float(parts[2])
        except ValueError:
            continue
        gw.append((t, g))
        fpm.append((t, f))

    if len(gw) < 3:
        print("pas assez de relevés pour conclure quoi que ce soit", file=sys.stderr)
        return 1

    span_h = (gw[-1][0] - gw[0][0]) / 3600.0
    print("# Endurance — dérive mémoire\n")
    print(f"Durée observée : **{span_h:.2f} h** · {len(gw)} relevés\n")
    print("| Conteneur | Départ (MiB) | Fin (MiB) | Δ (MiB) | Pente (MiB/h) |")
    print("|---|---|---|---|---|")
    for label, series in (("`ecoshield-gateway`", gw), ("`ecoshield-legacy-fpm`", fpm)):
        m, _ = slope(series)
        print(
            f"| {label} | {series[0][1]:.1f} | {series[-1][1]:.1f} | "
            f"{series[-1][1] - series[0][1]:+.1f} | {m * 3600:+.2f} |"
        )

    m_gw, _ = slope(gw)
    per_hour = m_gw * 3600
    print()

    # Une fuite est une pente POSITIVE. Une pente négative veut dire que la
    # mémoire redescend — typiquement l'allocateur qui rend un pic antérieur —
    # et la traiter comme un défaut au même titre qu'une croissance reviendrait
    # à signaler une bonne nouvelle.
    if per_hour > 1.0:
        print(
            f"Pente de la passerelle : **+{per_hour:.2f} MiB/h** — croissance "
            f"significative, soit +{per_hour * 24:.0f} MiB par jour. À instruire "
            f"avant toute mise en production : c'est la signature d'une fuite.\n"
        )
    elif per_hour < -1.0:
        print(
            f"Pente de la passerelle : **{per_hour:.2f} MiB/h** — la mémoire "
            f"REDESCEND. Ce n'est pas une fuite : c'est un pic antérieur que "
            f"l'allocateur rend. Le relevé a donc démarré avant que le worker ne "
            f"soit stabilisé — laisser la pile se poser avant de charger (voir la "
            f"phase de repos de `soak.sh`) rend la fenêtre exploitable.\n"
        )
    else:
        print(
            f"Pente de la passerelle : **{per_hour:+.2f} MiB/h**, sous le bruit de "
            f"l'allocateur — aucune fuite décelable sur cette fenêtre.\n"
        )

    print(
        f"> Portée : sur {span_h:.2f} h, une fuite plus lente que "
        f"~{max(abs(per_hour), 0.5):.1f} MiB/h resterait indétectable. Seul "
        f"l'allongement de la fenêtre resserre cette borne — beta6 a soaké 3 h par "
        f"moteur pour la descendre à ~1.7 MiB/h.\n"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1] if len(sys.argv) > 1 else "bench/results/soak-mem.csv"))
