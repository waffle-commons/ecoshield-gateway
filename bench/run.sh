#!/usr/bin/env bash
# =============================================================================
# Protocole de mesure EcoShield.
#
# k6 seul mesure la latence. La promesse du projet porte d'abord sur la MÉMOIRE,
# qui ne se lit pas côté client : ce script échantillonne donc `docker stats` en
# parallèle de la charge, puis rapproche les deux (bench/report.py).
#
# k6 tourne DANS le réseau compose et s'adresse aux services par leur nom : les
# deux chemins mesurés traversent alors exactement la même pile réseau, et la
# redirection de ports de l'hôte ne pollue aucun des deux.
#
#   ./bench/run.sh
#   VUS=64 DURATION=60s ./bench/run.sh
# =============================================================================
set -euo pipefail

cd "$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"

VUS="${VUS:-20}"
DURATION="${DURATION:-30s}"
OUT="${OUT:-bench/results}"
NETWORK="${NETWORK:-ecoshield-gateway_default}"
mkdir -p "$OUT"

echo "→ vérification de la pile…"
curl -fsS "http://localhost:${GATEWAY_PORT:-8099}/__ecoshield/health" >/dev/null
curl -fsS "http://localhost:${LEGACY_PORT:-8098}/health" >/dev/null

echo "→ échantillonnage des ressources (2 s)…"
printf 'epoch;container;mem;cpu\n' > "$OUT/stats.csv"
(
  while true; do
    now=$(date +%s)
    docker stats --no-stream --format "${now};{{.Name}};{{.MemUsage}};{{.CPUPerc}}" \
      ecoshield-gateway ecoshield-legacy-fpm ecoshield-legacy-nginx ecoshield-redis \
      >> "$OUT/stats.csv" 2>/dev/null || true
    sleep 2
  done
) &
SAMPLER=$!
trap 'kill "$SAMPLER" 2>/dev/null || true' EXIT

echo "→ k6 : 4 scénarios x ${DURATION} à ${VUS} VUs…"
docker run --rm --network "$NETWORK" \
  -e GATEWAY_URL="http://gateway:80" \
  -e LEGACY_URL="http://legacy-nginx:80" \
  -e VUS="$VUS" -e DURATION="$DURATION" \
  -v "$PWD/bench:/bench" \
  grafana/k6:latest run --summary-export=/bench/results/summary.json /bench/gateway-vs-legacy.js \
  2>&1 | tee "$OUT/k6.txt"

kill "$SAMPLER" 2>/dev/null || true
sleep 1
echo "→ synthèse…"
python3 bench/report.py "$OUT" | tee "$OUT/REPORT.md"
