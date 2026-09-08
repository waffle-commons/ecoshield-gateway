<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
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
}
