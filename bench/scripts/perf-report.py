#!/usr/bin/env python3
"""Synthèse de la campagne à générateur natif.

Reprend la discipline de `bench/drift.py` — une dérive se juge sur la PENTE
d'une régression et sur la borne en dessous de laquelle une fuite resterait
invisible, jamais sur l'écart entre deux instantanés — et l'applique aux deux
grandeurs que le banc relève :

  * le RSS du conteneur (vérité de l'exploitant, granularité mégaoctet) ;
  * le tas PHP du worker (vérité du code, granularité octet).

Les deux sont nécessaires. Le RSS seul ne peut pas voir une dérive de quelques
centaines de kio ; le tas seul ne verrait pas une fuite qui vit hors de PHP
(Caddy, le client cURL, l'opcache).
"""
from __future__ import annotations

import json
import pathlib
import sys

# --------------------------------------------------------------------------
# Régression — identique à bench/drift.py, gardée ici pour que ce script
# reste exécutable seul.
# --------------------------------------------------------------------------


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


def slope_stderr(points: list[tuple[float, float]]) -> float:
    """Erreur-type de la pente — ce qui transforme un chiffre en affirmation.

    Une pente nue ne dit pas si elle se distingue de zéro. La demi-largeur de
    l'intervalle de confiance donne la borne réelle de détection : en dessous,
    une fuite est indiscernable d'une mémoire plate SUR CETTE FENÊTRE.
    """
    n = len(points)
    if n < 3:
        return float("inf")
    m, b0 = slope(points)
    mx = sum(p[0] for p in points) / n
    sxx = sum((p[0] - mx) ** 2 for p in points)
    if sxx == 0:
        return float("inf")
    sse = sum((y - (m * x + b0)) ** 2 for x, y in points)
    return ((sse / (n - 2)) / sxx) ** 0.5


def read_csv(path: pathlib.Path) -> tuple[list[str], list[list[float]]]:
    if not path.is_file():
        return [], []
    lines = path.read_text().splitlines()
    if len(lines) < 2:
        return [], []
    header = lines[0].split(",")
    rows: list[list[float]] = []
    for line in lines[1:]:
        parts = line.split(",")
        if len(parts) != len(header):
            continue
        try:
            rows.append([float(p) for p in parts])
        except ValueError:
            continue
    return header, rows


def series(header: list[str], rows: list[list[float]], column: str) -> list[tuple[float, float]]:
    if column not in header:
        return []
    idx = header.index(column)
    return [(r[0], r[idx]) for r in rows]


MIB = 1024.0 * 1024.0
KIB = 1024.0


def drift_row(label: str, points: list[tuple[float, float]], unit: float, unit_name: str) -> str:
    if len(points) < 3:
        return f"| {label} | — | — | — | relevés insuffisants |"
    m, _ = slope(points)
    per_hour = m * 3600 / unit
    ci = 1.96 * slope_stderr(points) * 3600 / unit
    start, end = points[0][1] / unit, points[-1][1] / unit
    verdict = "compatible avec zéro" if abs(per_hour) <= ci else "**hors borne**"
    return (
        f"| {label} | {start:.2f} | {end:.2f} | {end - start:+.2f} | "
        f"{per_hour:+.2f} {unit_name}/h (±{ci:.2f}) | {verdict} |"
    )


def envelope(points: list[tuple[float, float]], buckets: int = 12) -> tuple[list, list]:
    """Enveloppes haute et basse d'une série qui alterne entre plusieurs workers.

    En mode worker FrankenPHP chaque worker porte son propre tas : la série brute
    saute d'un niveau à l'autre selon le worker qui a servi le relevé, et une
    régression sur le brut mesurerait surtout l'ordre d'arrivée. Découper la
    fenêtre en tranches et ne garder que le max (puis le min) de chaque tranche
    sépare le signal — si AUCUNE des deux enveloppes ne monte, aucun worker ne
    fuit.
    """
    if len(points) < buckets:
        return points, points
    t0, t1 = points[0][0], points[-1][0]
    span = max(t1 - t0, 1e-9)
    hi: dict[int, tuple[float, float]] = {}
    lo: dict[int, tuple[float, float]] = {}
    for t, v in points:
        b = min(int((t - t0) / span * buckets), buckets - 1)
        if b not in hi or v > hi[b][1]:
            hi[b] = (t, v)
        if b not in lo or v < lo[b][1]:
            lo[b] = (t, v)
    return [hi[k] for k in sorted(hi)], [lo[k] for k in sorted(lo)]


def mean_window(points: list[tuple[float, float]], fraction: float, tail: bool) -> float | None:
    """Moyenne du premier (ou dernier) `fraction` de la fenêtre.

    C'est le critère du banc écosystème (BENCH-03) : ΔM = moyenne de fin −
    moyenne de début. Il est complémentaire de la pente — la pente dit s'il y a
    une tendance, ΔM dit de combien la mémoire a effectivement bougé.
    """
    if not points:
        return None
    t0, t1 = points[0][0], points[-1][0]
    span = t1 - t0
    if span <= 0:
        return points[0][1]
    if tail:
        window = [v for t, v in points if t >= t1 - span * fraction]
    else:
        window = [v for t, v in points if t <= t0 + span * fraction]
    return sum(window) / len(window) if window else None


# --------------------------------------------------------------------------
# k6
# --------------------------------------------------------------------------


def metric(summary: dict, name: str) -> dict:
    m = summary.get("metrics", {}).get(name)
    if not m:
        return {}
    return m.get("values", m)


def fmt(v, digits: int = 2, dash: str = "—") -> str:
    return dash if v is None else f"{float(v):.{digits}f}"


def latency_table(summary: dict) -> list[str]:
    rows = ["| Chemin | requêtes | min | p50 | p90 | p95 | p99 | p99.9 | max |", "|---|---:|---:|---:|---:|---:|---:|---:|---:|"]
    labels = {
        "rescue": "`rescue` — servi par le worker",
        "shield": "`shield` — servi du cache",
        "proxy": "`proxy` — traverse jusqu'au monolithe",
    }
    for tag, label in labels.items():
        d = metric(summary, f"http_req_duration{{path:{tag}}}")
        c = metric(summary, f"http_reqs{{path:{tag}}}")
        if not d:
            continue
        rows.append(
            f"| {label} | {fmt(c.get('count'), 0)} | {fmt(d.get('min'))} | {fmt(d.get('med'))} | "
            f"{fmt(d.get('p(90)'))} | {fmt(d.get('p(95)'))} | {fmt(d.get('p(99)'))} | "
            f"{fmt(d.get('p(99.9)'))} | {fmt(d.get('max'))} |"
        )
    return rows


def thresholds_table(summary: dict) -> list[str]:
    rows = ["| Seuil | Verdict |", "|---|---|"]
    for name, m in sorted(summary.get("metrics", {}).items()):
        th = m.get("thresholds")
        if not th:
            continue
        for expr, state in th.items():
            # k6 rend soit {'ok': bool}, soit un booléen direct selon la version.
            ok = state.get("ok") if isinstance(state, dict) else state
            rows.append(f"| `{name}` {expr} | {'PASS' if ok else '**FAIL**'} |")
    return rows if len(rows) > 2 else []


def checks_table(summary: dict) -> tuple[list[str], int, int]:
    """Les assertions de périmètre, une ligne par assertion."""
    rows = ["| Assertion | passées | échouées | Verdict |", "|---|---:|---:|---|"]
    total_ok = total_ko = 0

    def walk(node):
        nonlocal total_ok, total_ko
        for chk in node.get("checks", []) or []:
            name = chk.get("name", "?")
            ok = int(chk.get("passes", 0))
            ko = int(chk.get("fails", 0))
            total_ok += ok
            total_ko += ko
            rows.append(f"| {name} | {ok} | {ko} | {'OK' if ko == 0 else '**KO**'} |")
        # k6 a rendu `groups` tantôt comme un objet indexé par nom, tantôt comme
        # une liste (v2). Les deux formes sont acceptées : un rapport qui tombe
        # en marche sur une montée de version du générateur ne vaut rien.
        groups = node.get("groups") or []
        for group in (groups.values() if isinstance(groups, dict) else groups):
            walk(group)

    root = summary.get("root_group") or summary.get("rootGroup") or {}
    walk(root)
    return (rows if total_ok + total_ko else []), total_ok, total_ko


def main(directory: str) -> int:
    out = pathlib.Path(directory)

    print("# Campagne de mesure — générateur natif\n")
    print(
        "Produit par `bench/scripts/perf-report.py`. Les chiffres viennent des "
        "fichiers bruts de `bench/results/` ; aucun n'est saisi à la main.\n"
    )

    # --- environnement -----------------------------------------------------
    env_path = out / "perf-environment.json"
    if env_path.is_file():
        env = json.loads(env_path.read_text())
        host, docker = env.get("host", {}), env.get("docker", {})
        fp = env.get("frankenphp", {})
        workers = (fp.get("workers") or [{}])[0].get("num")
        print("## Topologie mesurée\n")
        print("| | |")
        print("|---|---|")
        print(f"| Hôte | {host.get('model')} · {host.get('physical_cpus')} cœurs physiques / {host.get('logical_cpus')} logiques · {int(host.get('memory_bytes', 0)) / 1024**3:.0f} Gio |")
        print(f"| Système | {host.get('os')} (macOS {host.get('product_version')}) |")
        print(f"| Docker | {docker.get('server_version')} · VM {docker.get('vm_cpus')} vCPU / {int(docker.get('vm_memory_bytes', 0)) / 1024**3:.1f} Gio |")
        print(f"| Générateur | {env.get('k6', '').splitlines()[0] if env.get('k6') else '—'}, **natif sur l'hôte** |")
        print(f"| FrankenPHP | {fp.get('num_threads')} threads · **{workers} workers** résidents |")
        gw = env.get("containers", {}).get("ecoshield-gateway", {})
        nano = gw.get("nano_cpus") or 0
        print(f"| Passerelle | bornée à {nano / 1e9:.1f} vCPU · {int(gw.get('memory_bytes') or 0) / 1024**3:.1f} Gio |")
        print(f"| `MAX_REQUESTS` | {env.get('gateway_env', {}).get('MAX_REQUESTS')} (recyclage worker neutralisé) |")
        print(f"| Sockets hôte | `somaxconn`={host.get('somaxconn')} · `tcp.msl`={host.get('tcp_msl')} · `ulimit -n`={host.get('ulimit_n')} |")
        print()

    # --- soak --------------------------------------------------------------
    soak_path = out / "perf-soak.json"
    if soak_path.is_file():
        summary = json.loads(soak_path.read_text())
        reqs = metric(summary, "http_reqs")
        failed = metric(summary, "http_req_failed")
        dur = metric(summary, "http_req_duration")

        print("## Débit et latence\n")
        # Piège de lecture : sur une métrique `rate`, k6 nomme `passes` le nombre
        # de fois où le prédicat était VRAI. Pour `http_req_failed`, le prédicat
        # est « la requête a échoué » : c'est donc `passes` qui compte les
        # échecs, et `fails` qui compte les succès. Lire `fails` publierait le
        # nombre de requêtes réussies sous l'étiquette « échecs ».
        print(
            f"**{fmt(reqs.get('count'), 0)} requêtes** à **{fmt(reqs.get('rate'))} req/s** soutenues · "
            f"taux d'échec **{fmt((failed.get('rate') or 0) * 100, 3)} %** "
            f"({fmt(failed.get('passes'), 0)} requêtes en échec sur "
            f"{fmt((failed.get('passes') or 0) + (failed.get('fails') or 0), 0)}).\n"
        )
        print("Latence en millisecondes, par chemin — les trois ne mesurent pas la même chose :\n")
        print("\n".join(latency_table(summary)))
        print()
        print(
            f"Toutes requêtes confondues : p50 {fmt(dur.get('med'))} ms · "
            f"p95 {fmt(dur.get('p(95)'))} ms · p99 {fmt(dur.get('p(99)'))} ms · "
            f"max {fmt(dur.get('max'))} ms. *Ce chiffre global mélange trois chemins "
            f"de coûts différents ; il est donné pour mémoire, pas comme un SLA.*\n"
        )

        th = thresholds_table(summary)
        if th:
            print("### Seuils déclarés\n")
            print("\n".join(th))
            print()

    # --- mémoire -----------------------------------------------------------
    print("## Invariant mémoire\n")

    heap_header, heap_rows = read_csv(out / "perf-heap.csv")
    if heap_rows:
        heap = series(heap_header, heap_rows, "heap_bytes")
        peak = series(heap_header, heap_rows, "peak_bytes")
        real = series(heap_header, heap_rows, "heap_real_bytes")
        hi, lo = envelope(heap)

        span_min = (heap[-1][0] - heap[0][0]) / 60.0
        print(
            f"### Tas PHP du worker — {len(heap)} relevés sur {span_min:.1f} min\n"
        )
        print(
            "Granularité à l'octet : c'est la seule résolution capable de trancher "
            "un critère exprimé en kio. Les deux enveloppes séparent les workers, "
            "dont les tas alternent d'un relevé à l'autre.\n"
        )
        print("| Série | début | fin | Δ | pente (IC 95 %) | verdict |")
        print("|---|---:|---:|---:|---:|---|")
        print(drift_row("enveloppe haute (kio)", hi, KIB, "kio"))
        print(drift_row("enveloppe basse (kio)", lo, KIB, "kio"))
        print(drift_row("tas brut — *indicatif*", heap, KIB, "kio").replace(
            "| compatible avec zéro |", "| — |").replace("| **hors borne** |", "| — |"))
        print(drift_row("pic cumulé (kio)", peak, KIB, "kio"))
        print(drift_row("blocs OS (Mio)", real, MIB, "Mio"))
        print()
        print(
            "> **La ligne « tas brut » ne porte volontairement aucun verdict.** La série "
            "brute alterne entre les tas de plusieurs workers selon celui qui a servi le "
            "relevé : une régression dessus mesure surtout la proportion de relevés tombés "
            "sur l'un ou l'autre, et peut afficher une pente franche alors que le début et "
            "la fin sont rigoureusement identiques. Le verdict appartient aux deux "
            "enveloppes — si aucune ne monte, aucun worker ne fuit.\n"
        )

        first = mean_window(hi, 0.2, tail=False)
        last = mean_window(hi, 0.2, tail=True)
        if first is not None and last is not None:
            delta_kib = (last - first) / KIB
            # Le critère de la mission : ≤ 256 kio de dérive nette sur la fenêtre.
            # Il ne peut se juger QUE sur le tas PHP — le RSS du conteneur bouge
            # par mégaoctets pour des raisons étrangères à PHP (arènes Go,
            # opcache), et `memory_get_usage(true)` par paliers de 2 Mio.
            verdict = "PASS" if abs(delta_kib) <= 256 else "**FAIL**"
            print(
                f"**ΔM (critère écosystème BENCH-03)** — moyenne des 20 % finaux moins "
                f"moyenne des 20 % initiaux, sur l'enveloppe haute : **{delta_kib:+.1f} kio**.\n"
            )
            print(
                f"**Critère d'endurance (≤ 256 kio de dérive nette) : {verdict}** "
                f"— |{delta_kib:+.1f}| kio contre 256 kio autorisés, sur le tas PHP du "
                f"worker, seule grandeur dont la résolution permette de trancher un seuil "
                f"exprimé en kio.\n"
            )

    for label, path, container in (
        ("Passerelle", out / "perf-rss-gateway.csv", "ecoshield-gateway"),
        ("Monolithe (PHP-FPM)", out / "perf-rss-fpm.csv", "ecoshield-legacy-fpm"),
    ):
        header, rows = read_csv(path)
        if not rows:
            continue
        rss = series(header, rows, "rss_bytes")
        span_min = (rss[-1][0] - rss[0][0]) / 60.0 if len(rss) > 1 else 0.0
        print(f"### RSS conteneur — {label} (`{container}`)\n")
        print(f"{len(rss)} relevés sur {span_min:.1f} min.\n")
        print("| Série | début | fin | Δ | pente (IC 95 %) | verdict |")
        print("|---|---:|---:|---:|---:|---|")
        print(drift_row("RSS (Mio)", rss, MIB, "Mio"))
        print()
        if span_min < 30:
            print(
                f"> **Ce que cette fenêtre ne peut pas dire.** La pente est exprimée "
                f"par heure à partir de {span_min:.1f} min de relevés : l'extrapolation "
                f"multiplie le bruit par {60 / max(span_min, 1e-9):.0f}. Une pente qui "
                f"sort de sa borne ici ne démontre PAS une fuite — elle dit que la "
                f"fenêtre est trop courte pour trancher, et c'est exactement la limite "
                f"que `BENCH-03` (beta6) traitait en soakant 3 h par moteur. Le chiffre "
                f"solide sur une fenêtre courte est le Δ observé, pas son extrapolation.\n"
            )

    # --- périmètre ---------------------------------------------------------
    perim_path = out / "perf-perimeter.json"
    if perim_path.is_file():
        summary = json.loads(perim_path.read_text())
        rows, ok, ko = checks_table(summary)
        failed = metric(summary, "http_req_failed")
        print("## Périmètre — assertions négatives\n")
        print(
            f"> **Lecture.** k6 compte ici un taux d'échec HTTP de "
            f"{fmt((failed.get('rate') or 0) * 100, 1)} % : c'est ATTENDU et c'est le "
            f"résultat recherché. Ce scénario envoie majoritairement des requêtes "
            f"qui DOIVENT être refusées, et un 400 compte comme un échec HTTP pour "
            f"le générateur. Le verdict de ce scénario est la colonne des "
            f"assertions ci-dessous, jamais `http_req_failed`.\n"
        )
        if rows:
            print("\n".join(rows))
            print()
        print(
            f"**{ok} assertions passées, {ko} échouées.** "
            + ("Aucun contournement observé.\n" if ko == 0 else "**Contournement observé.**\n")
        )

    raw_path = out / "perf-perimeter-raw.txt"
    if raw_path.is_file():
        print("### Cadrage des messages (socket brute)\n")
        print(raw_path.read_text().split("\n", 2)[-1].strip())
        print()

    sockets = out / "perf-sockets.txt"
    if sockets.is_file():
        print(f"> {sockets.read_text().strip()}\n")

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1] if len(sys.argv) > 1 else "bench/results"))
