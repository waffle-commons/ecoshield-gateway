<?php

declare(strict_types=1);

// This is the bootstrap file for PHPUnit.

// Set error reporting to the highest level
error_reporting(E_ALL);

// Set default timezone (optional, but good practice for consistency)
date_default_timezone_set('UTC');

// Include the Composer autoloader
$autoloader = require dirname(__DIR__) . '/vendor/autoload.php';

if (!$autoloader) {
    echo "Composer autoloader not found. Please run 'composer install'." . PHP_EOL;
    exit(1);
}

// Constantes d'application : la fabrique du kernel les lit comme le fait
// public/index.php, ce qui permet au test de fumée d'assembler la passerelle
// réelle plutôt qu'une réplique approximative de son câblage.
define('APP_ROOT', realpath(dirname(__DIR__)));
const APP_CONFIG = 'config';

// Valeurs d'environnement du test : aucune n'est jointe pendant les tests — la
// passerelle est assemblée, pas connectée.
putenv('APP_ENV=dev');
putenv('APP_DEBUG=true');
putenv('UPSTREAM_URL=http://legacy.invalid:80');
putenv('CACHE_ADAPTER=array');
