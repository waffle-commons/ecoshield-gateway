#!/usr/bin/env bash
# =============================================================================
# bench/scripts/perf-run.sh — campagne de mesure à générateur NATIF.
#
# Le harnais historique (`bench/run.sh`, `bench/ladder.sh`, `bench/soak.sh`) fait
# tourner k6 DANS le réseau compose. `bench/BENCH-RESULT.md` en désigne lui-même
# la limite : au-delà de 16 VUs, la VM Docker héberge à la fois les conteneurs et
# le générateur, et l'échelle mesure la contention de l'hôte plutôt qu'une
# architecture.
#
# Cette campagne-ci corrige ce que l'on peut corriger sans seconde machine :
#
#   1. k6 tourne NATIVEMENT sur l'hôte (jamais dans un conteneur sur macOS) ;
#   2. les conteneurs sont bornés en CPU (`docker-compose.perf.yml`), donc la VM
#      ne peut plus affamer le générateur ;
#   3. le débit est FIXÉ (modèle ouvert) au lieu d'être subi.
#
# Ordre des phases, et pourquoi cet ordre :
#
#   préchauffe → REPOS → relevés → charge → analyse
#
# Le REPOS n'est pas décoratif. Le premier soak de la campagne beta6 a été jeté
# parce que le relevé démarrait sur le pic laissé par la charge précédente : la
# mémoire redescendait pendant toute la fenêtre et la régression rendait une
# pente négative qui ne décrivait rien (`bench/results/SOAK-unsettled.md`). On
# mesure une dérive à partir d'un état stabilisé, jamais la décrue d'un pic.
#
#   bench/scripts/perf-run.sh all
#   RATE=300 DURATION=3m bench/scripts/perf-run.sh soak
#   bench/scripts/perf-run.sh perimeter
# =============================================================================
set -euo pipefail

cd "$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"

MODE="${1:-all}"

GATEWAY_PORT="${GATEWAY_PORT:-8099}"
LEGACY_PORT="${LEGACY_PORT:-8098}"
TARGET="http://localhost:${GATEWAY_PORT}"
LEGACY_TARGET="http://localhost:${LEGACY_PORT}"

RATE="${RATE:-300}"
DURATION="${DURATION:-3m}"
WARMUP="${WARMUP:-30s}"
SETTLE="${SETTLE:-45}"
REPEATS="${REPEATS:-20}"

OUT="${OUT:-bench/results}"
mkdir -p "$OUT"

GATEWAY_CT='ecoshield-gateway'
FPM_CT='ecoshield-legacy-fpm'

say() { printf '\n\033[1m→ %s\033[0m\n' "$*"; }

# ---------------------------------------------------------------------------
# Préambule — refuser de mesurer plutôt que publier un chiffre faux
# ---------------------------------------------------------------------------
preflight() {
  say 'contrôles préalables'

  command -v k6 >/dev/null || { echo 'k6 absent de l hôte (brew install k6)' >&2; exit 1; }
  echo "  k6            $(k6 version 2>&1 | head -1)"

  # k6 dans un conteneur sur macOS repasserait par la pile réseau de la VM :
  # c'est exactement le biais que cette campagne corrige.
  echo '  générateur    natif (hôte), jamais conteneurisé'

  # Aucun conteneur étranger : un voisin bruyant sur les mêmes vCPU invalide
  # silencieusement toute la campagne.
  local strangers
  strangers="$(docker ps --format '{{.Names}}' | grep -v '^ecoshield-' || true)"
  if [[ -n "$strangers" ]]; then
    echo "  ✗ conteneurs étrangers en cours d exécution :" >&2
    printf '      %s\n' $strangers >&2
    echo '    Les arrêter avant de mesurer — ils disputent les mêmes vCPU.' >&2
    exit 1
  fi
  echo "  voisinage     seuls les conteneurs ecoshield tournent"

  curl -fsS "${TARGET}/__ecoshield/health" >/dev/null \
    || { echo "passerelle injoignable sur ${TARGET}" >&2; exit 1; }
  echo "  passerelle    ${TARGET} vivante"

  # La sonde mémoire DOIT être ouverte, sinon le soak tournerait et l'invariant
  # mémoire ne serait mesuré qu'au RSS — soit deux ordres de grandeur trop gros.
  curl -fsS "${TARGET}/__ecoshield/memory" | grep -q 'heap_bytes' \
    || { echo 'sonde mémoire fermée : lancer la pile avec docker-compose.perf.yml' >&2; exit 1; }
  echo '  sonde mémoire ouverte'

  # La capacité déclarée du sujet, relevée et non supposée.
  local cfg
  cfg="$(docker exec "$GATEWAY_CT" sh -c 'curl -fsS http://localhost:2019/config/' 2>/dev/null \
    | python3 -c 'import json,sys; f=json.load(sys.stdin)["apps"]["frankenphp"]; print(f"{f[\"num_threads\"]} threads / {f[\"workers\"][0][\"num\"]} workers")' 2>/dev/null || echo 'inconnue')"
  echo "  FrankenPHP    ${cfg}"
}

environment_snapshot() {
  # La topologie est un résultat, pas un détail d'exécution : un chiffre de
  # latence sans la capacité des deux camps n'est pas reproductible.
  python3 - "$OUT/perf-environment.json" <<'PY'
import json, subprocess, sys, platform, datetime

def sh(cmd, default=''):
    try:
        return subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=30).stdout.strip()
    except Exception:
        return default

env = {
    'captured_at': datetime.datetime.now(datetime.UTC).isoformat(),
    'host': {
        'model': sh('sysctl -n hw.model'),
        'logical_cpus': int(sh('sysctl -n hw.logicalcpu') or 0),
        'physical_cpus': int(sh('sysctl -n hw.physicalcpu') or 0),
        'memory_bytes': int(sh('sysctl -n hw.memsize') or 0),
        'os': f"{platform.system()} {platform.release()}",
        'product_version': sh('sw_vers -productVersion'),
        'somaxconn': sh('sysctl -n kern.ipc.somaxconn'),
        'tcp_msl': sh('sysctl -n net.inet.tcp.msl'),
        'ulimit_n': sh('ulimit -n'),
    },
    'docker': {
        'server_version': sh("docker info --format '{{.ServerVersion}}'"),
        'vm_cpus': int(sh("docker info --format '{{.NCPU}}'") or 0),
        'vm_memory_bytes': int(sh("docker info --format '{{.MemTotal}}'") or 0),
    },
    'k6': sh('k6 version'),
    'containers': {},
}

for name in ('ecoshield-gateway', 'ecoshield-legacy-fpm', 'ecoshield-legacy-nginx', 'ecoshield-redis'):
    raw = sh(f"docker inspect {name} --format '{{{{json .HostConfig}}}}'")
    try:
        hc = json.loads(raw)
        env['containers'][name] = {
            'nano_cpus': hc.get('NanoCpus'),
            'cpu_quota': hc.get('CpuQuota'),
            'memory_bytes': hc.get('Memory'),
        }
    except Exception:
        env['containers'][name] = {}

fp = sh("docker exec ecoshield-gateway sh -c 'curl -fsS http://localhost:2019/config/'")
try:
    env['frankenphp'] = json.loads(fp)['apps']['frankenphp']
except Exception:
    env['frankenphp'] = {}

env['gateway_env'] = {
    k: sh(f"docker exec ecoshield-gateway sh -c 'printenv {k}'")
    for k in ('WORKER_COUNT', 'PHP_NUM_THREADS', 'MAX_REQUESTS', 'ECOSHIELD_DIAGNOSTICS', 'APP_ENV')
}

with open(sys.argv[1], 'w') as fh:
    json.dump(env, fh, indent=2)
print(f"  environnement -> {sys.argv[1]}")
PY
}

# ---------------------------------------------------------------------------
# Le soak
# ---------------------------------------------------------------------------
run_soak() {
  say "préchauffe (${WARMUP})"
  # Scénario DÉDIÉ, qui n'écrit aucun résumé. Réutiliser `soak.js` pour
  # préchauffer produisait `perf-soak.json` dès la chauffe : un soak interrompu
  # aurait alors laissé le résumé de la préchauffe en place, et le rapport aurait
  # publié la chauffe comme s'il s'agissait de la mesure. Voir l'en-tête de
  # `bench/k6/lib/warmup.js`.
  k6 run --quiet \
    -e TARGET="$TARGET" -e RATE=100 -e DURATION="$WARMUP" \
    bench/k6/lib/warmup.js >/dev/null 2>&1 || true

  say "repos (${SETTLE}s) avant le premier relevé"
  # Voir l'en-tête : sans ce repos, la fenêtre démarre sur le pic de la
  # préchauffe et la régression mesure une décrue, pas une dérive.
  sleep "$SETTLE"

  say 'démarrage des relevés mémoire'
  bench/scripts/sample-memory.sh "$GATEWAY_CT" "$OUT/perf-rss-gateway.csv" 5 &
  local rss_gw=$!
  bench/scripts/sample-memory.sh "$FPM_CT" "$OUT/perf-rss-fpm.csv" 5 &
  local rss_fpm=$!
  bench/scripts/sample-worker-heap.sh "${TARGET}/__ecoshield/memory" "$OUT/perf-heap.csv" 2 &
  local heap=$!

  stop_samplers() {
    kill "$rss_gw" "$rss_fpm" "$heap" 2>/dev/null || true
    wait "$rss_gw" "$rss_fpm" "$heap" 2>/dev/null || true
  }
  trap stop_samplers EXIT

  say "soak : ${RATE} req/s pendant ${DURATION} (modèle ouvert)"
  k6 run \
    -e TARGET="$TARGET" -e RATE="$RATE" -e DURATION="$DURATION" \
    bench/k6/scenarios/soak.js 2>&1 | tee "$OUT/perf-soak-k6.txt"

  stop_samplers
  trap - EXIT

  # Le compte des sockets en TIME_WAIT tranche empiriquement la question de
  # l'épuisement des ports éphémères, plutôt que de la supposer réglée.
  local tw
  tw="$(netstat -an 2>/dev/null | grep -c 'TIME_WAIT' || echo 0)"
  echo "  sockets TIME_WAIT sur l hôte après la charge : ${tw}" | tee "$OUT/perf-sockets.txt"
}

# ---------------------------------------------------------------------------
# Le périmètre
# ---------------------------------------------------------------------------
run_perimeter() {
  say "périmètre : assertions négatives (${REPEATS} répétitions)"
  k6 run \
    -e TARGET="$TARGET" -e REPEATS="$REPEATS" \
    bench/k6/scenarios/perimeter.js 2>&1 | tee "$OUT/perf-perimeter-k6.txt"

  say 'périmètre : cadrage des messages (socket brute)'
  bench/scripts/perimeter-raw.sh localhost "$GATEWAY_PORT"
}

# ---------------------------------------------------------------------------
preflight
environment_snapshot

case "$MODE" in
  soak)      run_soak ;;
  perimeter) run_perimeter ;;
  all)       run_soak; run_perimeter ;;
  *) echo "usage: $0 [all|soak|perimeter]" >&2; exit 2 ;;
esac

say 'analyse'
python3 bench/scripts/perf-report.py "$OUT" | tee "$OUT/PERF-REPORT.md" >/dev/null
echo "  rapport -> $OUT/PERF-REPORT.md"
