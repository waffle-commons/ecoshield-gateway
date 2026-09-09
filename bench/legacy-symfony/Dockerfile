# syntax=docker/dockerfile:1
#
# Le monolithe legacy — vrai Symfony, sur PHP-FPM.
#
# Ce que cette image change par rapport au stand-in `legacy/public/index.php` :
# UNE SEULE VARIABLE. Même version de PHP (8.3), même image de base, même pool
# FPM, même Nginx, même plafond mémoire, même opcache. Seule l'application
# change : un `usleep` plus 3 000 `stdClass` deviennent un vrai noyau Symfony
# reconstruit à chaque requête.
#
# C'est la discipline du banc écosystème beta6 : on ne compare deux mesures que
# si tout, sauf ce que l'on veut mesurer, est tenu identique.
#
# Le code est COPIÉ dans l'image, jamais monté. Le montage passe par virtiofs sur
# macOS et l'opcache revalide alors chaque fichier : `bench/BENCH-RESULT.md`
# consigne une série entière perdue à cette erreur.
ARG PHP_VERSION='8.3'

FROM php:${PHP_VERSION}-fpm-alpine

# opcache est le seul ajout à l'image de base, et il n'est pas optionnel : aucun
# déploiement PHP de production ne s'en passe. Voir php/opcache.ini.
RUN docker-php-ext-install opcache

COPY bench/legacy-symfony/php/opcache.ini /usr/local/etc/php/conf.d/zz-bench.ini
COPY legacy/php-fpm/www.conf /usr/local/etc/php-fpm.d/zz-bench.conf

WORKDIR /var/www/html

# L'application est générée par bench/legacy-symfony/bootstrap.sh et n'est pas
# suivie en versionnement (même convention que `bench/engines/symfony-app/` côté
# écosystème) : elle pèse des dizaines de mégaoctets de `vendor/` et se
# reconstruit à l'identique depuis le script et les stubs suivis.
COPY bench/legacy-symfony/app/ /var/www/html/

# Le cache prod embarque le chemin absolu du projet au moment du dump : il est
# donc reconstruit ICI, à l'emplacement définitif, plutôt que repris de l'hôte.
RUN rm -rf var/cache/prod var/log \
    && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup --env=prod --no-debug \
    && chown -R www-data:www-data var
