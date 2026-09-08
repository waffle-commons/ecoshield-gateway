<?php

declare(strict_types=1);

use App\Factory\AppKernelFactory;
use Waffle\Commons\Config\DotEnv;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Http\Emitter\ResponseEmitter;
use Waffle\Commons\Http\Factory\GlobalsFactory;
use Waffle\Commons\Runtime\WaffleRuntime;

require_once __DIR__ . '/../vendor/autoload.php';

define('APP_ROOT', realpath(path: dirname(path: __DIR__)));
const APP_CONFIG = 'config';

// DotEnv ne mute pas l'environnement global : son retour DOIT être capté, sinon
// $env et $debug retombent silencieusement sur leurs défauts quel que soit le
// contenu réel du .env ou des variables Docker. L'environnement du processus
// l'emporte, pour que la configuration du conteneur ait le dernier mot.
$envRegistry = array_merge(new DotEnv(path: APP_ROOT)->load(), getenv());
$env = $envRegistry[Constant::APP_ENV] ?? Constant::ENV_PROD;
$debug = filter_var($envRegistry[Constant::APP_DEBUG] ?? false, FILTER_VALIDATE_BOOL);

$kernel = AppKernelFactory::create(env: $env, debug: $debug);

// La boucle worker : le kernel reste en mémoire, seule la requête change.
// C'est toute la thèse FinOps du projet — le coût de démarrage est payé une
// fois par worker, pas une fois par requête comme sous PHP-FPM.
$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 500);

new WaffleRuntime(new GlobalsFactory(uploadBaseDir: APP_ROOT . '/var/uploads'), new ResponseEmitter())
    ->loop(kernel: $kernel, maxRequests: $maxRequests);
