<?php

declare(strict_types=1);

/**
 * Préchargement OPcache.
 *
 * Exécuté une fois au démarrage du serveur, il charge le framework en mémoire
 * partagée. Sur une passerelle, c'est le complément naturel du mode worker :
 * le kernel est déjà construit, et maintenant son code est déjà compilé.
 */

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    return;
}

require __DIR__ . '/../vendor/autoload.php';

(static function (): void {
    $root = dirname(__DIR__);

    foreach ([$root . '/vendor/waffle-commons', $root . '/src'] as $path) {
        if (!is_dir($path)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            // Les tests et fixtures des paquets vendored n'ont rien à faire en
            // mémoire partagée : ils ne seront jamais appelés en production.
            if (str_contains($file->getPathname(), '/tests/')) {
                continue;
            }

            opcache_compile_file($file->getPathname());
        }
    }
})();
