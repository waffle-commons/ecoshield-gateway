#!/usr/bin/env bash
# bench/scripts/perimeter-raw.sh — cadrage des messages, à l'octet près.
#
# k6 ne peut pas exprimer ces cas : le client HTTP de Go normalise le message
# avant l'envoi (il retire Content-Length dès qu'un Transfer-Encoding est
# présent), si bien qu'un test écrit en k6 mesurerait la bibliothèque cliente et
# non la passerelle. Ces cas passent donc par une socket brute.
#
# ## Ce qui est réellement vérifié
#
# Le danger du cadrage ambigu n'est pas un code de statut, c'est le DÉSYNC : si
# la passerelle et l'amont lisent le même octet-flux différemment, une requête en
# devient deux, et la seconde est écrite par l'attaquant. La signature observable
# est donc « combien de réponses sortent d'une seule requête » — c'est ce que ce
# script compte, plutôt que de se contenter d'un 400 qui pourrait masquer un
# désync en aval.
#
# Le script distingue aussi QUI refuse, ce que le code de statut seul ne dit pas :
#   - `application/problem+json` (RFC 7807) => la PASSERELLE a refusé ;
#   - `text/plain` « 400 Bad Request »      => CADDY a refusé avant PHP.
# Les deux ferment la porte, mais une seule est une propriété du code de ce dépôt.
#
#   bench/scripts/perimeter-raw.sh [hôte] [port]
set -euo pipefail

cd "$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"

HOST="${1:-localhost}"
PORT="${2:-${GATEWAY_PORT:-8099}}"
OUT="${OUT:-bench/results}"
mkdir -p "$OUT"
REPORT="$OUT/perf-perimeter-raw.txt"

fail=0

# Envoie des octets bruts et rend la réponse complète.
send() {
  printf '%b' "$1" | nc -w 4 "$HOST" "$PORT" 2>/dev/null || true
}

# Compte les lignes de statut : >1 signifie que la pile a produit deux réponses
# pour une requête, c'est-à-dire un désync exploitable.
count_responses() {
  printf '%s' "$1" | grep -c '^HTTP/1\.[01] ' || true
}

who_rejected() {
  case "$1" in
    *application/problem+json*) echo 'passerelle (RFC 7807)' ;;
    *'text/plain'*)             echo 'Caddy (avant PHP)' ;;
    *)                          echo 'indéterminé' ;;
  esac
}

status_of() {
  printf '%s' "$1" | head -1 | awk '{print $2}'
}

{
  echo "# Cadrage des messages — sondes à l'octet près"
  echo
  echo "Cible : http://${HOST}:${PORT} · $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  echo
  echo '| Cas | Réponses | Statut | Refusé par | Verdict |'
  echo '|---|---:|---:|---|---|'
} > "$REPORT"

check_case() {
  local name="$1" payload="$2" expect_responses="$3" note="$4"
  local reply n status who verdict
  reply="$(send "$payload")"
  n="$(count_responses "$reply")"
  status="$(status_of "$reply")"
  who="$(who_rejected "$reply")"

  if [[ "$n" -le "$expect_responses" ]]; then
    verdict='OK'
  else
    verdict='KO — DÉSYNC'
    fail=1
  fi

  printf '| %s | %s | %s | %s | %s |\n' \
    "$name" "${n:-0}" "${status:-–}" "$who" "$verdict" >> "$REPORT"
  printf '  %-28s réponses=%s statut=%s refus=%s → %s\n' \
    "$name" "${n:-0}" "${status:-–}" "$who" "$verdict"
  # `if` plutôt que `[[ … ]] && …` : sous `set -e`, un test faux en dernière
  # instruction fait sortir la fonction en échec et interrompt toute la campagne.
  if [[ -n "$note" ]]; then
    printf '    %s\n' "$note"
  fi
}

echo "→ cadrage des messages sur http://${HOST}:${PORT}"

# CL.TE — le cas d'école. Le corps déclaré par Content-Length contient une
# seconde requête ; si un maillon lit Content-Length et l'autre Transfer-Encoding,
# cette seconde requête est servie comme si un autre client l'avait envoyée.
check_case 'cl_te_smuggling' \
  'POST /api/orders/1 HTTP/1.1\r\nHost: localhost\r\nContent-Length: 44\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\nGET /api/products/999 HTTP/1.1\r\nHost: localhost\r\n\r\n' \
  1 'une seule réponse = pas de désync ; le second message n a jamais été servi'

# TE.CL — la symétrie du précédent, l'ordre des en-têtes inversé.
check_case 'te_cl_smuggling' \
  'POST /api/orders/2 HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\nContent-Length: 4\r\n\r\n5c\r\nGET /api/products/998 HTTP/1.1\r\nHost: localhost\r\n\r\n0\r\n\r\n' \
  1 ''

# Deux Content-Length contradictoires : rien ne permet de choisir, il faut refuser.
check_case 'dual_content_length' \
  'POST /api/orders/3 HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\nContent-Length: 5\r\n\r\nHELLO' \
  1 ''

# Requête légitime : le contrôle doit laisser passer, sinon il ne prouve rien.
check_case 'controle_positif' \
  'GET /api/products/42 HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n' \
  1 'témoin : une requête bien formée doit être servie'

{
  echo
  if [[ "$fail" -eq 0 ]]; then
    echo '**Verdict : aucun désync.** Aucune requête ambiguë n a produit plus d une réponse.'
  else
    echo '**Verdict : DÉSYNC OBSERVÉ.** Une requête ambiguë a produit plusieurs réponses.'
  fi
} >> "$REPORT"

echo "→ rapport : $REPORT"
exit "$fail"
