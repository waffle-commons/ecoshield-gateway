#!/usr/bin/env bash
# =============================================================================
# Test d'endurance — la mémoire est-elle VRAIMENT constante dans le temps ?
#
# L'échelle prouve que l'empreinte ne suit pas la concurrence. Elle ne prouve
# rien sur la durée : un worker qui fuit 1 Mio/h est irréprochable pendant dix
# minutes et mort au bout d'une semaine. C'est exactement la raison pour
# laquelle beta6 a soaké 3 h par moteur plutôt que de se fier à une rafale.
#
# Le verdict n'est pas « la mémoire a-t-elle bougé » — elle bouge toujours un
# peu — mais la PENTE : une régression linéaire sur les relevés, exprimée en
# Mio/heure, avec la borne au-delà de laquelle une fuite serait détectable.
#
#   ./bench/soak.sh                  # 30 min, 8 VUs
#   DURATION=3h VUS=16 ./bench/soak.sh
# =============================================================================
set -euo pipefail

cd "$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"

DURATION="${DURATION:-30m}"
VUS="${VUS:-8}"
SCENARIO="${SCENARIO:-mixed}"
NETWORK="${NETWORK:-ecoshield-gateway_default}"
OUT="${OUT:-bench/results}"
mkdir -p "$OUT"

SAMPLES="$OUT/soak-mem.csv"
printf 'epoch;gateway_mib;fpm_mib\n' > "$SAMPLES"

echo "→ endurance : ${DURATION} à ${VUS} VUs (scénario ${SCENARIO})"
curl -fsS "http://localhost:${GATEWAY_PORT:-8099}/__ecoshield/health" >/dev/null

# Repos avant relevé. Sans lui, la fenêtre démarre sur le pic laissé par la
# charge précédente : la mémoire redescend pendant tout le soak et la régression
# rend une pente négative qui ne décrit rien. On mesure une dérive à partir d'un
# état stabilisé, pas la décrue d'un pic.
SETTLE="${SETTLE:-60}"
echo "→ stabilisation (${SETTLE}s) avant le premier relevé…"
sleep "$SETTLE"

mem_of() {
  docker stats --no-stream --format '{{.MemUsage}}' "$1" 2>/dev/null \
    | awk '{print $1}' | sed 's/MiB//; s/GiB/*1024/' | bc -l 2>/dev/null || echo 0
}

(
  while true; do
    printf '%s;%s;%s\n' "$(date +%s)" "$(mem_of ecoshield-gateway)" "$(mem_of ecoshield-legacy-fpm)" >> "$SAMPLES"
    sleep 15
  done
) & SAMPLER=$!
trap '{ kill "$SAMPLER" && wait "$SAMPLER"; } 2>/dev/null || true' EXIT

docker run --rm --network "$NETWORK" \
  -e SCENARIO="$SCENARIO" -e VUS="$VUS" -e DURATION="$DURATION" \
  -v "$PWD/bench:/bench" \
  grafana/k6:latest run --quiet --summary-export=/bench/results/soak-summary.json /bench/soak.js \
  2>&1 | tail -25 | tee "$OUT/soak-k6.txt"

{ kill "$SAMPLER" && wait "$SAMPLER"; } 2>/dev/null || true
python3 bench/drift.py "$SAMPLES" | tee "$OUT/SOAK.md"
