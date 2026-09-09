# syntax=docker/dockerfile:1
#
# EcoShield Gateway — image FrankenPHP (mode worker).
#
ARG FRANKEN_VERSION='1.12.2'
ARG PHP_VERSION='8'
ARG OS='trixie'

FROM dunglas/frankenphp:${FRANKEN_VERSION}-php${PHP_VERSION}-${OS} AS base

# Pas de FRANKENPHP_CONFIG : le mode worker est déclaré dans la Caddyfile,
# qui est la seule à être lue dès lors qu'on en fournit une.
ENV APP_ENV=prod \
    SERVER_NAME=":80" \
    WORKER_COUNT=4

# Pas de pilote de base de données : la passerelle n'en ouvre aucune. Ce qui
# n'est pas installé ne peut pas être exploité, et l'image reste petite.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libyaml-dev \
    && install-php-extensions opcache intl yaml redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY docker/frankenphp/conf.d/app.ini $PHP_INI_DIR/conf.d/

WORKDIR /app

# -----------------------------------------------------------------------------
FROM base AS dev

ENV APP_ENV=dev

# Pilote de couverture — indispensable, et absent jusqu'ici.
#
# `phpunit.xml` déclare un rapport clover. Sans pilote, PHPUnit 12.5 ne se
# contente pas d'un avertissement : il charge la suite, signale « No code
# coverage driver available » et SORT EN ERREUR sans exécuter un seul test. La
# sortie « No tests executed! » ressemble alors à une suite vide, alors que les
# 45 tests avaient bien été trouvés — l'image de développement était incapable
# de faire tourner sa propre suite.
#
# pcov plutôt que xdebug : il ne fait que la couverture, pour une fraction du
# coût, et le débogage pas-à-pas n'est pas ce que cette image sert.
#
# Dans l'étage `dev` UNIQUEMENT : l'étage `prod` ne doit embarquer aucun
# instrument de mesure de code — c'est de la surface d'attaque et du ralentissement.
RUN install-php-extensions pcov

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]

# -----------------------------------------------------------------------------
FROM base AS builder

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --no-interaction --optimize-autoloader
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# -----------------------------------------------------------------------------
FROM base AS prod

ARG USER=ecoshield
ARG UID=1000
ARG GID=1000

# Caddy écrit son état (autosave, CA locale) : on le déplace hors du rootfs pour
# qu'il puisse rester en lecture seule.
ENV APP_ENV=prod \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=builder --link /app/vendor /app/vendor
COPY --from=builder --link /app/src /app/src
COPY --from=builder --link /app/config /app/config
COPY --from=builder --link /app/public /app/public

RUN mkdir -p var/cache/shield var/log /config /data

# Exécution sans privilèges. L'image de base accorde déjà
# CAP_NET_BIND_SERVICE au binaire frankenphp, donc :80 reste liable.
RUN groupadd -g ${GID} ${USER} \
    && useradd -u ${UID} -g ${USER} -d /app -s /usr/sbin/nologin ${USER} \
    && chown -R ${USER}:${USER} /app /config /data
USER ${USER}

CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]
