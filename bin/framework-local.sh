#!/usr/bin/env bash
# =============================================================================
# bin/framework-local.sh — construire la passerelle sur une version NON publiée
# de waffle-commons, depuis le monorepo voisin. Rien n'est versionné.
#
# La passerelle consomme les versions publiées sur Packagist. Entre deux
# publications (la 0.2.0 sur 0.1.0-beta7 avant sa sortie, la 0.3.0 sur beta8),
# ce script installe le framework depuis le monorepo :
#
#  - des copies propres (composer.json + src/) de chaque dépendance
#    waffle-commons vont dans var/framework-local/<composant>/ ;
#  - composer.local.json (= composer.json + dépôts « path », chaque contrainte
#    waffle-commons forcée à ^$VERSION) et composer.local.lock sont exclus par
#    .git/info/exclude ;
#  - Composer tourne dans le conteneur waffle-dev du monorepo (aucun PHP sur
#    l'hôte), avec COMPOSER=composer.local.json.
#
# Modes :
#   install  copie, régénère composer.local.json, part de composer.lock (mago,
#            phpunit et igor restent ceux du verrou) et ne met à jour que
#            waffle-commons/* et leurs dépendances ;
#   resync   recopie, puis `composer reinstall 'waffle-commons/*'` (la référence
#            d'un paquet « path » ne change pas quand seul src/ change).
#
# Retour à Packagist une fois la version publiée : supprimer composer.local.*
# et var/framework-local/, puis `composer update 'waffle-commons/*'`.
#
#   VERSION=0.1.0-beta7 bin/framework-local.sh install
#   bin/framework-local.sh resync
# =============================================================================
set -euo pipefail

GATEWAY="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
UMBRELLA="${WAFFLE_UMBRELLA:-$(cd -P -- "$GATEWAY/../.." && pwd)}"
VERSION="${VERSION:-0.1.0-beta7}"
MODE="${1:-install}"
CONTAINER='waffle-dev'
LOCAL="var/framework-local"

case "$GATEWAY" in
  "$UMBRELLA"/*) IN_CONTAINER="/waffle-commons/${GATEWAY#"$UMBRELLA"/}" ;;
  *) echo "la passerelle doit vivre sous le monorepo ($UMBRELLA)" >&2; exit 1 ;;
esac

exclude="$GATEWAY/.git/info/exclude"
for entry in /composer.local.json /composer.local.lock; do
  grep -qxF "$entry" "$exclude" 2>/dev/null || printf '%s\n' "$entry" >> "$exclude"
done

comps="$(python3 -I -c '
import json, sys
d = json.load(open(sys.argv[1]))
print(" ".join(sorted(k.split("/", 1)[1] for k in d["require"] if k.startswith("waffle-commons/"))))
' "$GATEWAY/composer.json")"

for c in $comps; do
  [ -f "$UMBRELLA/$c/composer.json" ] || { echo "composant absent du monorepo : $c" >&2; exit 1; }
  mkdir -p "$GATEWAY/$LOCAL/$c"
  rsync -a --delete --include='/composer.json' --include='/src/***' --exclude='*' \
    "$UMBRELLA/$c/" "$GATEWAY/$LOCAL/$c/"
done
echo "copiés depuis $UMBRELLA : $comps"

python3 -I - "$GATEWAY/composer.json" "$GATEWAY/composer.local.json" "$LOCAL" "$VERSION" $comps <<'PY'
import json, sys
src, dst, local, version, comps = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5:]
d = json.load(open(src))
for section in ("require", "require-dev"):
    for name in list(d.get(section, {})):
        if name.startswith("waffle-commons/"):
            d[section][name] = "^" + version
d["repositories"] = [{
    "type": "path",
    "url": local + "/*",
    "options": {"symlink": False, "versions": {f"waffle-commons/{c}": version for c in comps}},
}]
with open(dst, "w") as fh:
    json.dump(d, fh, indent=4)
    fh.write("\n")
PY

case "$MODE" in
  install)
    [ -f "$GATEWAY/composer.local.lock" ] || cp "$GATEWAY/composer.lock" "$GATEWAY/composer.local.lock"
    docker exec -w "$IN_CONTAINER" -e COMPOSER=composer.local.json "$CONTAINER" \
      composer update 'waffle-commons/*' --with-all-dependencies --no-interaction --no-progress
    ;;
  resync)
    docker exec -w "$IN_CONTAINER" -e COMPOSER=composer.local.json "$CONTAINER" \
      composer reinstall 'waffle-commons/*' --no-interaction --no-progress
    ;;
  *) echo "usage : $0 install|resync" >&2; exit 2 ;;
esac
