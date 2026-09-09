<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Pilier 1 — **Interception (Rescue)**.
 *
 * Les routes servies ici ne quittent jamais le worker : pas de proxy, pas de
 * base de données, pas de bootstrap de framework legacy. C'est la moitié
 * « Strangler Fig » du POC — on reprend une route à la fois au monolithe, en
 * commençant par celles qui lui coûtent le plus cher, pendant que tout le reste
 * continue d'être proxyfié sans rien changer côté client.
 *
 * L'écart mesurable tient à ce qui n'a PAS lieu : en mode worker, le framework
 * est déjà en mémoire quand la requête arrive. Il n'y a pas d'autoload, pas de
 * relecture de configuration, pas de reconstruction de conteneur — le coût de
 * démarrage que PHP-FPM paie à chaque requête, celui que la page d'accueil
 * annonce diviser par cinq.
 *
 * `#[PublicAccess]` : une passerelle d'infrastructure ne porte pas d'ABAC ; sans
 * cet opt-out explicite, le SecureContainer fail-closed répondrait 403.
 */
#[Route(path: '/', name: 'rescue_')]
final class RescueController extends BaseController
{
    /**
     * Sonde de vivacité. Répond sans toucher au legacy — c'est précisément ce
     * qui la rend utile : elle dit si LA PASSERELLE est vivante, pas si l'amont
     * l'est. Un orchestrateur qui redémarre la passerelle parce que le monolithe
     * est tombé ne fait qu'aggraver l'incident.
     *
     * @throws RenderingException
     */
    #[Route(path: '__ecoshield/health', methods: [Routing::METHOD_GET], name: 'health')]
    #[PublicAccess]
    public function health(): ResponseInterface
    {
        return $this->jsonResponse(data: [
            'status' => 'ok',
            'gateway' => 'ecoshield',
            'mode' => 'worker',
        ]);
    }

    /**
     * Route « secourue » de démonstration.
     *
     * Dans le scénario du POC, `/api/products/{id}` est un point chaud du
     * monolithe : lecture triviale, mais payée au prix fort parce que le
     * framework legacy redémarre pour la servir. EcoShield la sert nativement ;
     * le client ne voit aucune différence, sinon la latence.
     *
     * La charge utile est volontairement statique : ce qui est démontré ici est
     * le COÛT DU CHEMIN, pas la richesse du domaine. Une reprise réelle irait
     * chercher la donnée via `waffle-commons/data`.
     *
     * @throws RenderingException
     */
    #[Route(path: 'api/products/{id}', methods: [Routing::METHOD_GET], name: 'product')]
    #[PublicAccess]
    public function product(string $id): ResponseInterface
    {
        return $this->jsonResponse(data: [
            'id' => $id,
            'name' => 'Produit ' . $id,
            'served_by' => 'ecoshield-gateway',
            'note' => 'Route interceptée : servie depuis le worker, sans toucher au monolithe legacy.',
        ]);
    }

    /**
     * Sonde de diagnostic mémoire — le relevé vu du worker.
     *
     * `docker stats` mesure le RSS du CONTENEUR : il englobe Caddy, le runtime
     * Go, l'opcache et les arènes que l'allocateur n'a pas encore rendues au
     * noyau. C'est la vérité de l'exploitant, et c'est le chiffre publié par
     * `bench/BENCH-RESULT.md` — mais il est trop grossier pour trancher une
     * dérive de quelques centaines de kio. Cette route rend le chiffre que seul
     * PHP connaît : le tas du worker qui sert la requête.
     *
     * Les deux résolutions sont publiées parce qu'elles ne disent pas la même
     * chose, et que confondre les deux est l'erreur classique :
     *
     *  - `heap_bytes` (`memory_get_usage(false)`) — les octets réellement
     *    détenus par le tas PHP, à l'octet près. C'est la SEULE résolution
     *    capable de voir une dérive de l'ordre de 256 kio.
     *  - `heap_real_bytes` (`memory_get_usage(true)`) — les blocs réclamés à
     *    l'OS, qui bougent par paliers de 2 Mio. Un palier franchi en cours de
     *    soak dit que l'allocateur a dû s'agrandir : c'est un signal binaire,
     *    pas une mesure fine, et l'utiliser pour juger un seuil en kio ne
     *    mesurerait que la granularité de l'allocateur.
     *
     * Les `peak_*` sont monotones par thread : elles ne redescendent jamais et
     * donnent donc la ligne de plus haute eau depuis le démarrage du worker.
     *
     * En mode worker FrankenPHP, chaque worker porte SON PROPRE tas (PHP est
     * compilé en ZTS, l'allocateur est par thread) : deux relevés consécutifs
     * peuvent venir de deux workers différents. Un relevé isolé ne veut donc
     * rien dire, et l'analyse ne travaille que sur l'enveloppe — voir
     * `bench/scripts/perf-report.py`.
     *
     * Rien n'est compté, rien n'est mémorisé entre deux requêtes : un compteur
     * d'itérations serait exactement l'état que le soak cherche à prouver
     * absent, et il ferait tomber `wfl igor` sur le contrôleur qui l'héberge.
     *
     * La route est **fermée par défaut** : publier en permanence l'empreinte
     * mémoire d'une passerelle exposée renseigne un attaquant sur l'effet de ses
     * requêtes. `ECOSHIELD_DIAGNOSTICS=1` l'ouvre, et seul le banc le fait.
     *
     * @throws RenderingException
     */
    #[Route(path: '__ecoshield/memory', methods: [Routing::METHOD_GET], name: 'memory')]
    #[PublicAccess]
    public function memory(ConfigInterface $config): ResponseInterface
    {
        // `%env(...)%` rend une CHAÎNE, ou `null` quand la variable n'est pas
        // définie : `getBool()` lèverait sur "1" plutôt que de le lire. La
        // lecture suit donc celle de `public/index.php` pour `APP_DEBUG`, et
        // l'absence vaut fermeture.
        $flag = $config->getString('gateway.diagnostics');
        if ($flag === null || !filter_var($flag, FILTER_VALIDATE_BOOL)) {
            return $this->jsonResponse(data: [
                'error' => 'not_found',
                'detail' => 'La sonde de diagnostic est fermée (ECOSHIELD_DIAGNOSTICS).',
            ], status: 404);
        }

        return $this->jsonResponse(data: [
            'heap_bytes' => memory_get_usage(),
            'heap_real_bytes' => memory_get_usage(real_usage: true),
            'peak_bytes' => memory_get_peak_usage(),
            'peak_real_bytes' => memory_get_peak_usage(real_usage: true),
        ]);
    }
}
