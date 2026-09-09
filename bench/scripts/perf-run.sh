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
# Modes :
#
#   full       soak + échelle + courbe mémoire + périmètre (la campagne complète)
#   soak       endurance à débit constant — l'invariant mémoire
#   ladder     échelle de débit (modèle ouvert) — où la latence se dégrade
#   memscale   empreinte en fonction de la concurrence (modèle fermé)
#   perimeter  assertions négatives de sécurité
#   all        soak + périmètre (la campagne courte)
#
#   bench/scripts/perf-run.sh full
#   RATE=300 DURATION=3h bench/scripts/perf-run.sh soak
#   RATES=50,100,200,400 STEP_DURATION=2m bench/scripts/perf-run.sh ladder
#   VUS_STEPS=8,16,32,64 bench/scripts/perf-run.sh memscale
#   LADDER_WORKLOADS=rescue MEMSCALE_SUBJECTS='rescue legacy' bench/scripts/perf-run.sh full
#
# Durée indicative de `full` avec les défauts : ~55 min
#   soak 4 min · échelle 22 min (2 charges) · courbe mémoire 28 min · périmètre <1 min
# Un soak de qualité publication (DURATION=3h) porte le total à ~3 h 50.
#
# La campagne complète suppose le monolithe Symfony en place — sans quoi le
# stand-in synthétique répond, ce qui reste valide mais ne mesure pas la même
# chose (voir bench/legacy-symfony/bootstrap.sh) :
#
#   bench/legacy-symfony/bootstrap.sh
#   docker compose -f docker-compose.yml -f docker-compose.perf.yml \
#                  -f docker-compose.symfony.yml up -d --build
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

# Échelle de débit (modèle ouvert) et courbe mémoire (modèle fermé).
RATES="${RATES:-50,100,200,400,800}"
STEP_DURATION="${STEP_DURATION:-2m}"
VUS_STEPS="${VUS_STEPS:-8,16,32,64,128}"

# Les charges des deux expériences comparatives, et pourquoi CE défaut.
#
# `rescue` et `legacy` servent une charge utile STATIQUE : la passerelle ne
# consulte rien, le monolithe reconstruit son noyau puis répond de mémoire. La
# comparaison contient alors un déséquilibre — un camp fait une entrée-sortie,
# l'autre non — qui est précisément ce que l'ajout d'une base a corrigé.
#
# Le défaut porte donc sur `dbread` / `legacydb` : MÊME requête, MÊME base, MÊME
# ligne. Ce qui subsiste dans l'écart est le démarrage du framework, et rien
# d'autre. C'est aussi la seule charge qui tienne réellement la concurrence
# ouverte, ce dont la courbe mémoire a besoin : une route qui répond en
# microsecondes n'occupe pas un enfant PHP-FPM assez longtemps pour que le
# nombre d'enfants reflète la concurrence demandée.
#
# Les charges statiques restent disponibles pour isoler le coût du CHEMIN seul :
#   LADDER_WORKLOADS=rescue MEMSCALE_SUBJECTS='rescue legacy' …
LADDER_WORKLOADS="${LADDER_WORKLOADS:-dbread legacydb}"
MEMSCALE_SUBJECTS="${MEMSCALE_SUBJECTS:-dbread legacydb}"

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
# Échelle de débit — où la latence se dégrade, et où le GÉNÉRATEUR décroche
# ---------------------------------------------------------------------------
run_ladder() {
  say "préchauffe (${WARMUP})"
  k6 run --quiet -e TARGET="$TARGET" -e RATE=100 -e DURATION="$WARMUP" \
    bench/k6/lib/warmup.js >/dev/null 2>&1 || true

  say "repos (${SETTLE}s)"
  sleep "$SETTLE"

  say 'démarrage des relevés mémoire'
  bench/scripts/sample-memory.sh "$GATEWAY_CT" "$OUT/perf-ladder-rss-gateway.csv" 5 &
  local rss_gw=$!
  bench/scripts/sample-memory.sh "$FPM_CT" "$OUT/perf-ladder-rss-fpm.csv" 5 &
  local rss_fpm=$!
  trap 'kill "$rss_gw" "$rss_fpm" 2>/dev/null || true' EXIT

  # UNE charge à la fois, avec un repos entre les deux : l'échelle de la
  # passerelle et celle du monolithe ne doivent jamais tourner ensemble, sinon
  # chacune mesure la contention causée par l'autre.
  local workload
  for workload in $LADDER_WORKLOADS; do
    say "repos (${SETTLE}s) avant l'échelle « ${workload} »"
    sleep "$SETTLE"

    say "échelle : [${RATES}] req/s x ${STEP_DURATION}, charge « ${workload} »"
    # Une seule exécution k6 par charge : les marches sont découpées par
    # ÉTIQUETTE, pas par horodatage, donc aucun redécoupage n'est nécessaire.
    k6 run \
      -e TARGET="$TARGET" -e LEGACY_TARGET="$LEGACY_TARGET" \
      -e RATES="$RATES" -e STEP_DURATION="$STEP_DURATION" \
      -e WORKLOAD="$workload" \
      bench/k6/scenarios/ladder.js 2>&1 | tee "$OUT/perf-ladder-${workload}-k6.txt"
  done

  kill "$rss_gw" "$rss_fpm" 2>/dev/null || true
  trap - EXIT
}

# ---------------------------------------------------------------------------
# Courbe mémoire — l'empreinte en fonction de la CONCURRENCE
# ---------------------------------------------------------------------------
run_memscale() {
  say "préchauffe (${WARMUP})"
  k6 run --quiet -e TARGET="$TARGET" -e RATE=100 -e DURATION="$WARMUP" \
    bench/k6/lib/warmup.js >/dev/null 2>&1 || true

  # UNE marche à la fois, et UN sujet à la fois.
  #
  # Les deux sujets ne sont jamais chargés ensemble : ils se disputeraient les
  # cœurs, et la courbe mesurerait la contention au lieu de l'empreinte. Chaque
  # marche est aussi une exécution k6 distincte, avec son propre relevé RSS —
  # ce qui évite d'avoir à retrouver les frontières des marches dans un CSV
  # continu, exercice d'horodatage où une erreur ne se voit pas.
  local subject vus label rss_csv sampler container
  for subject in $MEMSCALE_SUBJECTS; do
    # Le conteneur RELEVÉ est celui qui sert la charge : mesurer le RSS de la
    # passerelle pendant qu'on charge le monolithe rendrait une courbe plate qui
    # ne décrit rien.
    case "$subject" in
      legacy | legacydb) container="$FPM_CT" ;;
      *) container="$GATEWAY_CT" ;;
    esac

    for vus in ${VUS_STEPS//,/ }; do
      label="memscale-${subject}-c${vus}"
      rss_csv="$OUT/perf-${label}-rss.csv"

      say "repos (${SETTLE}s) avant la marche ${subject} c=${vus}"
      # Le repos AVANT chaque marche est ce qui rend les points comparables :
      # sans lui, chaque marche démarrerait sur le pic laissé par la précédente
      # et la courbe monterait toute seule.
      sleep "$SETTLE"

      bench/scripts/sample-memory.sh "$container" "$rss_csv" 3 &
      sampler=$!

      printf '  %-10s c=%-4s ' "$subject" "$vus"
      k6 run --quiet \
        -e TARGET="$TARGET" -e LEGACY_TARGET="$LEGACY_TARGET" \
        -e SUBJECT="$subject" -e VUS_STEPS="$vus" \
        -e STEP_DURATION="$STEP_DURATION" -e LABEL="$label" \
        bench/k6/scenarios/memscale.js 2>&1 | grep -E 'latence|requêtes' || true

      kill "$sampler" 2>/dev/null || true
      wait "$sampler" 2>/dev/null || true
    done
  done
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
  ladder)    run_ladder ;;
  memscale)  run_memscale ;;
  all)       run_soak; run_perimeter ;;
  # La campagne complète, dans l'ordre où les phases doivent tomber : le soak en
  # premier (c'est lui qui exige l'état le plus reposé), puis l'échelle, puis la
  # courbe mémoire, et le périmètre en dernier — il est le seul dont le résultat
  # ne dépend pas de l'état thermique ni de la mémoire de la machine.
  full)      run_soak; run_ladder; run_memscale; run_perimeter ;;
  *) echo "usage: $0 [full|all|soak|ladder|memscale|perimeter]" >&2; exit 2 ;;
esac

say 'analyse'
python3 bench/scripts/perf-report.py "$OUT" | tee "$OUT/PERF-REPORT.md" >/dev/null
echo "  rapport -> $OUT/PERF-REPORT.md"
