#!/usr/bin/env bash
# bench/scripts/sample-worker-heap.sh — relevé du TAS PHP du worker.
#
# Usage : sample-worker-heap.sh <url-sonde> <sortie.csv> [intervalle-secondes]
#
# Colonnes : epoch,heap_bytes,heap_real_bytes,peak_bytes,peak_real_bytes
#
# Le RSS du conteneur bouge par mégaoctets ; le critère d'endurance se joue à
# quelques centaines de kilooctets. Seul PHP connaît ce chiffre-là, d'où la sonde
# `/__ecoshield/memory` (fermée par défaut, ouverte par le seul banc).
#
# AVERTISSEMENT DE LECTURE — en mode worker FrankenPHP, chaque worker porte son
# propre tas : deux relevés consécutifs peuvent venir de deux workers différents,
# et la série brute alterne donc entre plusieurs niveaux. Un relevé isolé ne veut
# rien dire ; c'est l'ENVELOPPE (min et max par fenêtre) qu'il faut lire, et
# c'est ce que fait perf-report.py.
set -euo pipefail

if [[ $# -lt 2 ]]; then
  echo "usage: $0 <url-sonde> <sortie.csv> [intervalle-secondes]" >&2
  exit 2
fi

PROBE="$1"
OUT="$2"
INTERVAL="${3:-2}"

RUNNING=1
trap 'RUNNING=0' TERM INT

mkdir -p "$(dirname "$OUT")"
echo "epoch,heap_bytes,heap_real_bytes,peak_bytes,peak_real_bytes" > "$OUT"

while [[ "$RUNNING" -eq 1 ]]; do
  body="$(curl -fsS --max-time 3 "$PROBE" 2>/dev/null || true)"
  if [[ -n "$body" ]]; then
    # Extraction sans dépendance : la sonde rend un objet plat d'entiers.
    printf '%s,%s\n' "$(date +%s)" \
      "$(printf '%s' "$body" | sed -E 's/[^0-9,]//g; s/^,+//; s/,+$//')" >> "$OUT"
  fi
  sleep "$INTERVAL" &
  wait $! || true
done
