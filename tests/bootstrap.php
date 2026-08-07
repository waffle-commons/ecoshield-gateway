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
