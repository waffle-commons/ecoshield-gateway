#!/usr/bin/env bash
# =============================================================================
# Échelle de concurrence — la mesure qui compte réellement.
#
# Un point de charge unique ne dit rien : saturé, il mesure une file d'attente ;
# à vide, il flatte tout le monde. Ce qui distingue un framework résident d'un
# framework reconstruit, c'est la PENTE — comment latence et mémoire évoluent
# quand la concurrence monte. C'est aussi la leçon de BENCH-05 (beta6) : le
# facteur RAM annoncé n'était pas défendable comme un chiffre plat, la pente si.
#
# Pour chaque marche : chaque chemin est chargé à son tour, la mémoire est
# relevée pendant la charge, et rien n'est comparé entre deux marches
# différentes.
#
#   ./bench/ladder.sh
#   STEPS="1 8 32" DURATION=10s ./bench/ladder.sh
# =============================================================================
set -euo pipefail

cd "$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"

STEPS="${STEPS:-1 2 4 8 16 32 64}"
DURATION="${DURATION:-15s}"
PATHS="${PATHS:-legacy rescue proxy shield}"
NETWORK="${NETWORK:-ecoshield-gateway_default}"
OUT="${OUT:-bench/results}"
mkdir -p "$OUT"

RESULTS="$OUT/ladder.csv"
printf 'path;vus;p50_ms;p95_ms;p99_ms;rps;gateway_mib;fpm_mib\n' > "$RESULTS"

mem_of() { # conteneur -> MiB utilisés
  docker stats --no-stream --format '{{.MemUsage}}' "$1" 2>/dev/null \
    | awk '{print $1}' \
    | sed 's/MiB//; s/GiB/*1024/' \
    | bc -l 2>/dev/null || echo 0
}

echo "→ échelle : [$STEPS] VUs x ${DURATION} par chemin"
for vus in $STEPS; do
  for path in $PATHS; do
    printf '  %-7s vus=%-3s ' "$path" "$vus"

    # Relevé mémoire en continu pendant CETTE marche uniquement.
    peak_gw=0; peak_fpm=0
    (
      while true; do
        mem_of ecoshield-gateway   >> "/tmp/ec_gw.$$"
        mem_of ecoshield-legacy-fpm >> "/tmp/ec_fpm.$$"
        sleep 1
      done
    ) & SAMPLER=$!

    summary="$OUT/step-${path}-${vus}.json"
    docker run --rm --network "$NETWORK" \
      -e SCENARIO="$path" -e VUS="$vus" -e DURATION="$DURATION" \
      -v "$PWD/bench:/bench" \
      grafana/k6:latest run --quiet --summary-export="/bench/results/$(basename "$summary")" \
      /bench/step.js >/dev/null 2>&1 || true

    { kill "$SAMPLER" && wait "$SAMPLER"; } 2>/dev/null || true
    peak_gw=$(sort -g "/tmp/ec_gw.$$" 2>/dev/null | tail -1 || echo 0)
    peak_fpm=$(sort -g "/tmp/ec_fpm.$$" 2>/dev/null | tail -1 || echo 0)
    rm -f "/tmp/ec_gw.$$" "/tmp/ec_fpm.$$"

    python3 - "$summary" "$path" "$vus" "$peak_gw" "$peak_fpm" "$RESULTS" <<'PY'
import json, sys
summary, path, vus, gw, fpm, out = sys.argv[1:7]
try:
    m = json.load(open(summary))["metrics"]["http_req_duration"]
    reqs = json.load(open(summary))["metrics"]["http_reqs"]
    row = f"{path};{vus};{m['med']:.2f};{m['p(95)']:.2f};{m['p(99)']:.2f};{reqs['rate']:.0f};{float(gw):.1f};{float(fpm):.1f}\n"
    print(f"p50={m['med']:.2f}ms p95={m['p(95)']:.2f}ms {reqs['rate']:.0f} rps  gw={float(gw):.0f}MiB fpm={float(fpm):.0f}MiB")
except Exception as exc:  # noqa: BLE001 - une marche ratée ne doit pas tuer l'échelle
    row = f"{path};{vus};;;;;{gw};{fpm}\n"
    print(f"(marche non exploitable : {exc})")
open(out, "a").write(row)
PY
  done
done

echo "→ échelle complète : $RESULTS"
