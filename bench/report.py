#!/usr/bin/env python3
"""Rapproche la latence mesurée par k6 et la mémoire relevée par docker stats.

La latence sans la mémoire raconterait la moitié de l'histoire : la thèse
d'EcoShield est qu'on sert *plus vite* avec *moins de RAM*, et la seconde moitié
ne se lit que côté serveur.
"""
from __future__ import annotations

import json
import pathlib
import sys

PHASES = [
    ("legacy_direct", "Legacy direct (Nginx + PHP-FPM)"),
    ("gateway_proxy", "Passerelle — proxy (cache neutralisé)"),
    ("gateway_shield", "Passerelle — Shield (mutualisable)"),
    ("gateway_rescue", "Passerelle — route reprise"),
]
TRENDS = {
    "legacy_direct": "ec_legacy_direct_ms",
    "gateway_proxy": "ec_gateway_proxy_ms",
    "gateway_shield": "ec_gateway_shield_ms",
    "gateway_rescue": "ec_gateway_rescue_ms",
}


def to_mib(raw: str) -> float:
    """`docker stats` rend « 12.34MiB / 7.65GiB » : seule la partie utilisée compte."""
    used = raw.split("/")[0].strip()
    for suffix, factor in (("GiB", 1024.0), ("MiB", 1.0), ("KiB", 1 / 1024.0), ("B", 1 / 1048576.0)):
        if used.endswith(suffix):
            return float(used[: -len(suffix)]) * factor
    return 0.0


def main(directory: str) -> int:
    out = pathlib.Path(directory)
    summary_path = out / "summary.json"
    if not summary_path.is_file():
        print(f"pas de résumé k6 dans {summary_path}", file=sys.stderr)
        return 1

    metrics = json.loads(summary_path.read_text())["metrics"]

    print("# Résultats de mesure — EcoShield Gateway\n")
    print("## Latence (ms)\n")
    print("| Chemin | p50 | p95 | p99 | max | req/s |")
    print("|---|---|---|---|---|---|")
    for key, label in PHASES:
        trend = metrics.get(TRENDS[key])
        if not trend:
            continue
        print(
            f"| {label} | {trend['med']:.2f} | {trend['p(95)']:.2f} | "
            f"{trend['p(99)']:.2f} | {trend['max']:.2f} | — |"
        )

    http = metrics.get("http_reqs", {})
    failed = metrics.get("http_req_failed", {})
    print(f"\nRequêtes totales : **{int(http.get('count', 0))}** "
          f"({http.get('rate', 0):.0f}/s toutes phases confondues) · "
          f"taux d'échec **{failed.get('value', 0) * 100:.2f} %**\n")

    stats_path = out / "stats.csv"
    if stats_path.is_file():
        peaks: dict[str, float] = {}
        for line in stats_path.read_text().splitlines()[1:]:
            parts = line.split(";")
            if len(parts) < 4:
                continue
            _, name, mem, _cpu = parts[0], parts[1], parts[2], parts[3]
            mib = to_mib(mem)
            peaks[name] = max(peaks.get(name, 0.0), mib)

        if peaks:
            print("## Mémoire — pic observé sous charge (MiB)\n")
            print("| Conteneur | Pic RSS |")
            print("|---|---|")
            for name in sorted(peaks):
                print(f"| `{name}` | {peaks[name]:.1f} |")

            gw = peaks.get("ecoshield-gateway")
            fpm = peaks.get("ecoshield-legacy-fpm")
            if gw and fpm:
                print(
                    f"\nRapport passerelle / monolithe : **{gw / fpm:.2f}×** "
                    f"({gw:.1f} MiB contre {fpm:.1f} MiB).\n"
                )
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1] if len(sys.argv) > 1 else "bench/results"))
