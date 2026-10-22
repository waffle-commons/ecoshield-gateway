<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le monolithe legacy — version « vrai Symfony ».
 *
 * Le stand-in précédent (`legacy/public/index.php`) simulait le coût d'un
 * bootstrap avec 3 000 `stdClass` et un `usleep`. C'était honnête sur l'ORDRE DE
 * GRANDEUR, et clairement étiqueté comme tel, mais cela ne pouvait pas soutenir
 * une affirmation FinOps face à un vrai monolithe : personne ne déploie un
 * `usleep`.
 *
 * Ici, le coût mesuré est réel — conteneur de services compilé puis rechargé,
 * `HttpKernel`, routage, répartiteur d'événements, requête et réponse
 * reconstruites — et il est payé À CHAQUE REQUÊTE, parce que PHP-FPM ne garde
 * rien entre deux. C'est exactement ce que le mode worker supprime, et donc
 * exactement ce que la comparaison doit contenir.
 *
 * Les charges utiles restent volontairement triviales : ce qui est mesuré est le
 * COÛT DU CHEMIN, pas la richesse d'un domaine métier. Un contrôleur qui ferait
 * un vrai travail applicatif ajouterait la même charge des deux côtés et
 * diluerait la seule variable qui nous intéresse.
 */
final class LegacyController
{
    /**
     * Connexion injectée par le CONSTRUCTEUR, et non en argument d'action.
     *
     * L'injection de services dans les arguments d'une action suppose que le
     * contrôleur porte le tag `controller.service_arguments`. Le squelette
     * Symfony 5.4 ne le pose PAS : son `services.yaml` se contente d'enregistrer
     * `App\` en autowiring, sans bloc dédié aux contrôleurs. Une action typée
     * `Connection $connection` échoue alors à l'exécution, avec un message qui
     * parle de valeur manquante plutôt que de tag absent.
     *
     * Le constructeur, lui, est autowiré par la seule déclaration `App\` — sans
     * dépendre d'un tag que le squelette pourrait ou non poser.
     */
    public function __construct(private readonly Connection $connection) {}

    /** Sonde de vivacité — utilisée par le harnais, jamais par la mesure. */
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'service' => 'legacy-symfony']);
    }

    /**
     * Lecture chaude : la route que le POC « reprend » côté passerelle.
     *
     * C'est la comparaison qui porte le message du Strangler Fig — la MÊME
     * réponse, servie une fois par un framework reconstruit, une fois par un
     * worker résident.
     */
    public function product(string $id): JsonResponse
    {
        return new JsonResponse([
            'id' => $id,
            'name' => 'Produit ' . $id,
            'served_by' => 'legacy-symfony',
            'note' => 'Servi par le monolithe : le noyau Symfony vient d\'être reconstruit pour cette seule requête.',
            'peak_memory_kb' => (int) round(memory_get_peak_usage(true) / 1024),
        ]);
    }

    /** Route jamais reprise : elle traverse la passerelle jusqu'ici à chaque fois. */
    public function order(string $id): JsonResponse
    {
        return new JsonResponse([
            'id' => $id,
            'served_by' => 'legacy-symfony',
            'note' => 'Route proxyfiée : EcoShield ne l\'a pas reprise.',
            'peak_memory_kb' => (int) round(memory_get_peak_usage(true) / 1024),
        ]);
    }

    /**
     * Route mutualisable : la seule que le Shield a le droit de mettre en cache.
     *
     * ## Une découverte du passage au vrai Symfony, à ne pas gommer
     *
     * Symfony pose `Cache-Control: no-cache, private` sur TOUTE réponse par
     * défaut. `ResponseCache::isStorable()` refuse — à juste titre — de
     * mutualiser une réponse marquée `private`. Conséquence : face à un vrai
     * monolithe Symfony, le Shield est **inerte par défaut**, et répond `BYPASS`
     * sur tout.
     *
     * Le stand-in synthétique n'émettait aucun en-tête de cache, si bien que
     * TOUT y était mutualisable. Il flattait donc le Shield par omission, et le
     * chiffre « 11.3× plus rapide » de `bench/BENCH-RESULT.md` a été obtenu dans
     * ces conditions-là.
     *
     * Le rendre explicitement `public` ici n'est pas un artifice de banc : c'est
     * exactement le geste qu'un exploitant doit poser, endpoint par endpoint,
     * pour qu'une passerelle de cache serve à quelque chose. Les autres routes
     * gardent délibérément le défaut privé de Symfony — c'est ce contraste qui
     * rend la contrainte visible dans la mesure plutôt que dans une note de bas
     * de page.
     */
    public function catalogue(): JsonResponse
    {
        $response = new JsonResponse([
            'items' => ['alpha', 'beta', 'gamma'],
            'served_by' => 'legacy-symfony',
            'peak_memory_kb' => (int) round(memory_get_peak_usage(true) / 1024),
        ]);

        $response->setPublic();
        $response->setMaxAge(30);

        return $response;
    }

    /**
     * Lecture indexée — la contrepartie EXACTE de la route reprise.
     *
     * C'est la comparaison qui compte désormais : la même requête, sur la même
     * base, la même ligne, des deux côtés. La passerelle exécute
     * `SELECT ... WHERE id = ?` sur PDO ; le monolithe fait de même sur DBAL,
     * après avoir reconstruit son noyau. Ce qui subsiste dans l'écart est le coût
     * du démarrage — plus le fait qu'un camp interroge une base et l'autre non.
     *
     * Requête paramétrée, jamais de concaténation. Réponse `200 {found:false}`
     * sur identifiant inconnu plutôt que 404, pour que le banc ne mélange pas
     * deux distributions de latence sous un même percentile.
     *
     * La réponse reste PRIVÉE (défaut de Symfony) : une lecture par identifiant
     * n'est pas mutualisable entre clients, et un cache de passerelle qui la
     * partagerait serait une fuite de données. Seul `catalogue()` est public.
     */
    public function user(string $id): JsonResponse
    {
        $row = $this->connection
            ->executeQuery('SELECT id, email, created_at FROM users WHERE id = ?', [$id])
            ->fetchAssociative();

        return new JsonResponse([
            'found' => $row !== false,
            'user' => $row === false ? null : $row,
            'served_by' => 'legacy-symfony',
            'peak_memory_kb' => (int) round(memory_get_peak_usage(true) / 1024),
        ]);
    }

    /**
     * Miroir d'en-têtes, pour le banc de périmètre UNIQUEMENT.
     *
     * Prouver que le client ne peut pas détourner la sortie de la passerelle
     * demande de voir ce que l'amont a REÇU. Sans ce miroir, l'assertion
     * « l'amont est épinglé » se déduit d'une lecture de code ; avec lui, elle se
     * mesure. Un amont réel n'offrirait jamais ce point d'accès — celui-ci n'est
     * joignable que par la passerelle, hors mesure.
     */
    public function echoHeaders(Request $request): JsonResponse
    {
        return new JsonResponse([
            'served_by' => 'legacy-symfony',
            'host' => $request->headers->get('host'),
            'x_forwarded_for' => $request->headers->get('x-forwarded-for'),
            'x_forwarded_host' => $request->headers->get('x-forwarded-host'),
            'x_forwarded_proto' => $request->headers->get('x-forwarded-proto'),
            'forwarded' => $request->headers->get('forwarded'),
            'x_real_ip' => $request->headers->get('x-real-ip'),
            'request_uri' => $request->getRequestUri(),
            'x_authenticated_user' => $request->headers->get('x-authenticated-user'),
            'x_waffle_assertion' => $request->headers->get('x-waffle-assertion'),
        ]);
    }
}
