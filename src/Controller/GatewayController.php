<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\GatewayException;
use App\Proxy\ProxyController;
use App\Shield\ResponseCache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;

/**
 * Piliers 2 et 3 — **Proxying (Transparent)** et **Shield (cache)**.
 *
 * Route attrape-tout de priorité minimale : tout ce qu'aucune route « rescue »
 * n'a revendiqué finit ici et part vers le monolithe. C'est ce qui rend la
 * migration incrémentale — reprendre une route consiste à en ajouter une dans
 * {@see RescueController}, sans jamais toucher au routage du legacy ni à
 * l'adressage vu par les clients.
 *
 * L'ordre est délibéré : le Shield est consulté AVANT le proxy (un hit ne
 * traverse pas le réseau) et alimenté APRÈS (on ne mémorise que ce que l'amont a
 * réellement rendu). Ce que le Shield refuse de mettre en cache est documenté
 * dans {@see ResponseCache} — la liste des refus y est plus longue que celle des
 * acceptations, ce qui est la bonne proportion pour un cache de passerelle.
 *
 * `#[PublicAccess]` : la passerelle relaie l'authentification du legacy sans la
 * rejouer ; l'ABAC fail-closed du framework répondrait sinon 403 à tout le
 * trafic proxyfié.
 */
#[Route(path: '/', name: 'gateway_')]
final class GatewayController extends BaseController
{
    /**
     * Attrape-tout : proxyfie la requête, en passant par le cache quand la
     * requête et la réponse s'y prêtent.
     *
     * `priority: -1000` garantit que cette route est examinée en dernier : toute
     * route native, présente ou future, la précède sans configuration.
     *
     * @throws GatewayException Quand le message entrant est impossible à relayer
     *                          sans risque, ou que l'amont est injoignable.
     */
    #[Route(
        path: '{path:.*}',
        methods: [
            Routing::METHOD_GET,
            Routing::METHOD_POST,
            Routing::METHOD_PUT,
            Routing::METHOD_PATCH,
            Routing::METHOD_DELETE,
            // Le contrat de routage n'énumère pas HEAD ni OPTIONS (aucune route
            // applicative n'en a besoin), mais une passerelle relaie tout ce que
            // le client envoie : les omettre renverrait 405 sur des méthodes que
            // le monolithe gère parfaitement.
            'HEAD',
            'OPTIONS',
        ],
        name: 'proxy',
        priority: -1000,
    )]
    #[PublicAccess]
    public function proxy(
        ServerRequestInterface $request,
        ProxyController $proxy,
        ResponseCache $shield,
    ): ResponseInterface {
        $cached = $shield->lookup($request, $this->responseFactory->createResponse());
        if ($cached instanceof ResponseInterface) {
            return $cached;
        }

        return $shield->store($request, $proxy->proxy($request));
    }
}
