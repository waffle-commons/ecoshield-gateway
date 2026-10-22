#!/usr/bin/env bash
# =============================================================================
# bench/legacy-symfony/bootstrap.sh — installe le monolithe Symfony du banc et du
# laboratoire, À L'IDENTIQUE.
#
# L'application (`bench/legacy-symfony/app/`) est suivie en versionnement, sans
# son `vendor/` ni son `var/` : `composer.json`, `composer.lock`, `symfony.lock`,
# la configuration et le contrôleur. Ce script n'en génère plus rien — il installe
# les dépendances depuis le verrou, puis vérifie que le cache prod se compile.
# Deux exécutions, sur deux machines, installent donc le même monolithe : celui
# que la campagne 0.1.1 a mesuré (Symfony 5.4.45, doctrine/dbal 4.4.4).
#
# Jusqu'à la 0.1.1, il le recréait à chaque fois (`create-project
# symfony/skeleton:5.4.*`, `composer require` sans contrainte, recettes Flex
# lues sur le réseau) : deux bancs pouvaient comparer deux monolithes différents
# sans que rien ne le signale.
#
# ## Pourquoi Symfony 5.4 et PHP 8.3
#
# Le sujet est un MONOLITHE LEGACY : la chose qu'un Strangler Fig encercle n'est
# jamais la dernière version en date, c'est celle qui tourne depuis des années.
# 5.4 est la ligne LTS de novembre 2021. PHP 8.3 est la version du stand-in
# d'origine : garder la même fait que le delta ne tient qu'à « stand-in
# synthétique → vrai framework ».
#
# ## Mettre le monolithe à jour (délibérément)
#
# `composer update` dans `app/`, avec le même PHP 8.3 (`config.platform.php`), puis
# on versionne le nouveau verrou — et on sait qu'on ne mesure plus le même sujet.
#
#   bench/legacy-symfony/bootstrap.sh            # installe depuis le verrou
#   bench/legacy-symfony/bootstrap.sh --refresh  # vide vendor/ et var/, puis réinstalle
# =============================================================================
set -euo pipefail

cd "$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"

HERE='bench/legacy-symfony'
APP="$HERE/app"

# Images épinglées par digest : une étiquette bouge, un digest non. PHP est celui
# de l'image FPM du monolithe (8.3) ; Composer est celui de l'image de la passerelle.
PHP_CLI_IMAGE='php:8.3-cli-alpine@sha256:25bcb4f1ce4f13651c0f89a6a2db2b14ac5a75555d3553fa03c83dff6d6966a8'
COMPOSER_IMAGE='composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee'

step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

if [[ "${1:-}" == '--refresh' ]]; then
  step "suppression de $APP/vendor et $APP/var"
  rm -rf "$APP/vendor" "$APP/var"
fi

# Tout s'exécute dans un conteneur jetable sur la MÊME version de PHP que la cible
# (aucun PHP n'est exécuté sur l'hôte — CLAUDE.md), avec l'utilisateur de l'hôte
# pour que les fichiers installés lui appartiennent.
php_run() {
  docker run --rm \
    -v "$PWD:/work" -w "/work/$APP" \
    -u "$(id -u):$(id -g)" \
    -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
    "$PHP_CLI_IMAGE" sh -c "$1"
}

step 'Composer, depuis son image épinglée'
# Le binaire de l'image officielle, plutôt que l'installeur de getcomposer.org :
# celui-ci était téléchargé et exécuté sans vérification de sa signature.
docker run --rm --entrypoint cat "$COMPOSER_IMAGE" /usr/bin/composer > "$HERE/.composer.phar"

if [[ ! -f "$APP/.env" ]]; then
  step "écriture de $APP/.env (valeurs publiques)"
  # Non versionné, parce qu'un `.env` généré par Flex porte un APP_SECRET tiré au
  # hasard. Le monolithe de banc n'a ni session ni CSRF : rien n'y est protégé,
  # et la valeur ci-dessous n'est pas un secret.
  printf '%s\n' \
    '# Écrit par bench/legacy-symfony/bootstrap.sh. Le monolithe de banc n'"'"'a ni' \
    '# session ni CSRF : APP_SECRET n'"'"'y protège rien et n'"'"'est pas un secret.' \
    'APP_ENV=prod' \
    'APP_SECRET=bench-legacy-not-a-secret' > "$APP/.env"
fi

step 'installation de production depuis le verrou (--no-dev, autoloader autoritaire)'
php_run "APP_ENV=prod php /work/$HERE/.composer.phar install --no-dev --classmap-authoritative --no-interaction --no-progress --no-scripts"

step 'validation : compilation du cache prod'
# Validation de compilation seulement. Le cache définitif est reconstruit DANS
# l'image (le conteneur dumpé embarque le chemin absolu du projet).
php_run 'rm -rf var/cache/prod && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup --env=prod --no-debug'

step 'terminé'
echo "  application : $APP  (installée depuis composer.lock)"
echo "  laboratoire : docker compose -f docker-compose.yml -f docker-compose.lab.yml up -d --build"
echo "  banc        : docker compose -f docker-compose.yml -f docker-compose.perf.yml -f docker-compose.symfony.yml up -d --build"
