#!/usr/bin/env bash
# bench/scripts/sample-memory.sh — relevé RSS d'un conteneur, une ligne par tick.
#
# Usage : sample-memory.sh <conteneur> <sortie.csv> [intervalle-secondes]
#
# Colonnes : epoch,rss_bytes,limit_bytes
#
# C'est la vérité de l'EXPLOITANT : ce que le noyau attribue au conteneur, donc
# Caddy, le runtime Go, l'opcache et les arènes que l'allocateur n'a pas encore
# rendues. Elle englobe le tas PHP sans s'y réduire, et c'est la raison pour
# laquelle le banc relève AUSSI le tas du worker
# (`sample-worker-heap.sh`) : le RSS dit si la machine tient, le tas dit si le
# code fuit. Confondre les deux fait conclure « fuite » sur une arène gardée par
# l'allocateur, ou « sain » sur une fuite masquée par une arène qui se recycle.
#
# Lancé en arrière-plan par perf-run.sh ; s'arrête proprement sur TERM/INT, ou
# quand le conteneur disparaît.
set -euo pipefail

if [[ $# -lt 2 ]]; then
  echo "usage: $0 <conteneur> <sortie.csv> [intervalle-secondes]" >&2
  exit 2
fi

CONTAINER="$1"
OUT="$2"
INTERVAL="${3:-5}"

RUNNING=1
trap 'RUNNING=0' TERM INT

mkdir -p "$(dirname "$OUT")"
echo "epoch,rss_bytes,limit_bytes" > "$OUT"

# « 123.4MiB / 1GiB » -> octets, pour les deux côtés.
to_bytes() {
  awk -v s="$1" 'BEGIN {
    n = s + 0
    if      (s ~ /KiB/) n *= 1024
    else if (s ~ /MiB/) n *= 1024*1024
    else if (s ~ /GiB/) n *= 1024*1024*1024
    else if (s ~ /kB/)  n *= 1000
    else if (s ~ /MB/)  n *= 1000*1000
    else if (s ~ /GB/)  n *= 1000*1000*1000
    printf "%.0f", n
  }'
}

while [[ "$RUNNING" -eq 1 ]]; do
  line="$(docker stats --no-stream --format '{{.MemUsage}}' "$CONTAINER" 2>/dev/null || true)"
  if [[ -z "$line" ]]; then
    break   # conteneur parti (démontage) : on arrête de relever.
  fi
  used="$(to_bytes "${line%%/*}")"
  limit="$(to_bytes "${line##*/}")"
  echo "$(date +%s),${used},${limit}" >> "$OUT"
  sleep "$INTERVAL" &
  wait $! || true   # sommeil interruptible : TERM nous arrête dans le tick
done
