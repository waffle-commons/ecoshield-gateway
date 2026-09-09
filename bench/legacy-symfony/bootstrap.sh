#!/usr/bin/env bash
# =============================================================================
# bench/legacy-symfony/bootstrap.sh — génère le monolithe Symfony du banc.
#
# L'application produite (`bench/legacy-symfony/app/`) n'est PAS suivie en
# versionnement : c'est un `vendor/` de plusieurs dizaines de mégaoctets qui se
# reconstruit à l'identique depuis ce script et les stubs suivis. Même
# convention que `bench/engines/symfony-app/` côté écosystème.
#
# ## Pourquoi Symfony 5.4 et pas la dernière version
#
# Parce que le sujet est un MONOLITHE LEGACY. Le POC EcoShield décrit une
# migration Strangler Fig : la chose qu'on étrangle n'est jamais la dernière
# version en date, c'est celle qui tourne depuis des années. 5.4 est la ligne LTS
# de novembre 2021 — exactement le genre de socle qu'une équipe cherche à
# encercler en 2026, et un choix bien plus représentatif que 7.x.
#
# ## Pourquoi PHP 8.3, la même version qu'avant
#
# Pour ne changer QU'UNE variable. Le monolithe précédent tournait déjà sur
# `php:8.3-fpm-alpine` ; garder cette version fait que la seule différence entre
# l'ancien banc et le nouveau est « stand-in synthétique → vrai framework ».
# Faire glisser PHP en même temps rendrait le delta inexplicable, et donnerait à
# quiconque conteste le chiffre un argument gratuit.
#
# (Un monolithe réellement ancien tournerait souvent sur PHP 7.4 ou 8.1. Mesurer
# cela répondrait à une AUTRE question — le coût de la version de PHP — et
# mérite sa propre campagne, pas d'être mélangé à celle-ci.)
#
# ## Pourquoi pas de base de données
#
# Le coût que ce banc mesure est celui du DÉMARRAGE payé à chaque requête, et un
# noyau Symfony reconstruit le paie déjà en entier. Ajouter PostgreSQL
# ajouterait un cinquième conteneur en compétition pour les mêmes cœurs, et
# rendrait la courbe mémoire de la marche haute illisible. C'est un ajout
# possible plus tard, pas un prérequis — et il répondrait à une question
# différente (« combien coûte une connexion par processus »).
#
#   bench/legacy-symfony/bootstrap.sh            # génère
#   bench/legacy-symfony/bootstrap.sh --refresh  # régénère depuis zéro
# =============================================================================
set -euo pipefail

cd "$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"

HERE='bench/legacy-symfony'
APP="$HERE/app"
STUBS="$HERE/stubs"

SYMFONY_LINE="${SYMFONY_LINE:-5.4.*}"
PHP_VERSION="${PHP_VERSION:-8.3}"

# Les paquets qui rendent le graphe de services représentatif d'un monolithe.
# Un squelette nu compile un conteneur minuscule et sous-estimerait le coût de
# démarrage qu'on prétend mesurer ; validator et serializer sont présents dans
# à peu près toutes les applications Symfony réelles.
PACKAGES=(symfony/validator symfony/serializer-pack)

step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

if [[ "${1:-}" == '--refresh' ]]; then
  step "suppression de $APP"
  rm -rf "$APP"
fi

# Tout passe par un conteneur jetable sur la MÊME version de PHP que la cible :
# composer résout les contraintes de plateforme d'après le PHP qui l'exécute, et
# résoudre sur une autre version produirait un `composer.lock` qui n'est pas
# celui du déploiement mesuré. (Aucun PHP n'est exécuté sur l'hôte — CLAUDE.md.)
php_run() {
  # L'installeur est TOUJOURS préfixé, séparé par un saut de ligne et non par
  # `&&` : le bloc se termine par `fi`, et enchaîner `fi && …` est une erreur de
  # syntaxe pour /bin/sh. Le piège est silencieux à l'écriture et bruyant à
  # l'exécution — il a coûté une première exécution de ce script.
  docker run --rm \
    -v "$PWD:/work" -w /work \
    -u "$(id -u):$(id -g)" \
    -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
    --entrypoint sh "php:${PHP_VERSION}-cli-alpine" -c "$INSTALL_COMPOSER
$1"
}

# Composer n'est pas dans l'image PHP officielle. On le dépose une fois dans
# l'arbre monté (donc conservé d'une exécution à l'autre, et ignoré par git)
# plutôt que dans le /tmp du conteneur, qui disparaît avec lui et ferait
# retélécharger l'installeur à chaque étape.
# Chemin ABSOLU dans le conteneur (/work est la racine du dépôt montée) : les
# étapes suivantes font `cd` dans l'application, et un chemin relatif ne
# désignerait alors plus rien.
COMPOSER_PHAR="/work/$HERE/.composer.phar"
INSTALL_COMPOSER="
  if [ ! -f '$COMPOSER_PHAR' ]; then
    php -r \"copy('https://getcomposer.org/installer', '/tmp/ci.php');\"
    php /tmp/ci.php --install-dir=\"\$(dirname '$COMPOSER_PHAR')\" --filename=\"\$(basename '$COMPOSER_PHAR')\" --quiet
  fi
  COMPOSER='php $COMPOSER_PHAR'
"

if [[ ! -f "$APP/composer.json" ]]; then
  step "composer create-project symfony/skeleton:$SYMFONY_LINE (PHP $PHP_VERSION)"
  php_run "\$COMPOSER create-project 'symfony/skeleton:$SYMFONY_LINE' '$APP' --no-interaction --no-progress --no-scripts"
else
  step "application déjà présente — création ignorée (script idempotent)"
fi

step 'épinglage de la plateforme sur PHP '"$PHP_VERSION"
# Sans cela, une future exécution sur une autre image résoudrait différemment.
php_run "cd '$APP' && \$COMPOSER config platform.php '${PHP_VERSION}.0' --no-interaction"

step "ajout des dépendances de banc : ${PACKAGES[*]}"
php_run "cd '$APP' && \$COMPOSER require --no-interaction --no-progress --no-scripts ${PACKAGES[*]}"

step 'application des stubs suivis (contrôleur, routes)'
cp -R "$STUBS/." "$APP/"

step 'installation de production (--no-dev, autoloader autoritaire)'
php_run "cd '$APP' && APP_ENV=prod \$COMPOSER install --no-dev --classmap-authoritative --no-interaction --no-progress --no-scripts"

step 'validation : compilation du cache prod'
# Validation de compilation seulement. Le cache définitif est reconstruit DANS
# l'image (le conteneur dumpé embarque le chemin absolu du projet).
php_run "cd '$APP' && rm -rf var/cache/prod && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup --env=prod --no-debug"

step 'terminé'
echo "  application : $APP  (non suivie en versionnement)"
echo "  construire  : docker compose -f docker-compose.yml -f docker-compose.perf.yml -f docker-compose.symfony.yml up -d --build"
