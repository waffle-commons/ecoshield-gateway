<?php

declare(strict_types=1);

/**
 * Le monolithe legacy — la chose qu'EcoShield protège.
 *
 * Ce n'est pas une vraie application Symfony, et le prétendre fausserait le
 * benchmark. C'est un stand-in honnête pour ce qui coûte réellement cher sous
 * Nginx + PHP-FPM : le fait que TOUT soit reconstruit à chaque requête, parce
 * que le processus qui a servi la requête précédente n'a rien gardé.
 *
 * Trois coûts sont donc simulés explicitement, et rien d'autre :
 *
 *  1. Le graphe d'objets du framework (conteneur, routeur, config) rebâti ;
 *  2. La mémoire que ce graphe occupe le temps de la requête ;
 *  3. L'attente d'une source de données.
 *
 * Ce qui est mesuré face à la passerelle est donc bien le coût du DÉMARRAGE,
 * pas l'écart entre deux qualités de code applicatif.
 */

$bootstrapStart = hrtime(true);

// (1) + (2) — reconstruction d'un graphe de services à chaque requête. Les
// objets sont conservés jusqu'à la fin de la requête, exactement comme le
// conteneur d'un framework, puis intégralement jetés.
$container = [];
for ($i = 0; $i < 3000; $i++) {
    $container['service_' . $i] = new stdClass();
    $container['service_' . $i]->id = $i;
    $container['service_' . $i]->config = ['name' => 'service_' . $i, 'tags' => ['legacy', 'monolith']];
}

// (3) — le temps d'aller chercher la donnée. Constant et modeste : le but est
// de rendre l'écart lisible, pas de truquer la démonstration.
usleep(15_000);

$bootstrapMs = (hrtime(true) - $bootstrapStart) / 1e6;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

header('Content-Type: application/json');
header('X-Legacy-Bootstrap-Ms: ' . round($bootstrapMs, 2));
header('X-Served-By: legacy-monolith');

$payload = match (true) {
    $path === '/health' => ['status' => 'ok', 'service' => 'legacy-monolith'],
    str_starts_with($path, '/api/products/') => [
        'id' => basename($path),
        'name' => 'Produit ' . basename($path),
        'served_by' => 'legacy-monolith',
        'note' => 'Servi par le monolithe : le framework vient d\'être reconstruit pour cette seule requête.',
    ],
    default => [
        'path' => $path,
        'served_by' => 'legacy-monolith',
        'note' => 'Route proxyfiée : EcoShield n\'a pas repris cette route, elle est passée au legacy.',
    ],
};

echo json_encode(
    $payload + [
        'bootstrap_ms' => round($bootstrapMs, 2),
        'peak_memory_kb' => (int) round(memory_get_peak_usage(true) / 1024),
    ],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
);
