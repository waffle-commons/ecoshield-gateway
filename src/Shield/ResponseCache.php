<?php

declare(strict_types=1);

namespace App\Shield;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Waffle\Commons\Contracts\Cache\CacheInterface;

/**
 * Le « Shield » : cache des réponses du monolithe legacy (pilier 3).
 *
 * Décharger la base de données sous-jacente est le but ; le faire *correctement*
 * suppose de refuser de mettre en cache bien plus de choses qu'on n'en accepte.
 *
 * ## Ce qui n'est jamais mis en cache, et pourquoi
 *
 *  - **Autre chose qu'un GET/HEAD.** Une écriture n'a pas de représentation
 *    réutilisable, et servir une réponse de POST à un autre client serait une
 *    fuite de données, pas une optimisation.
 *  - **Toute requête porteuse d'un `Authorization` ou d'un `Cookie`.** Ces
 *    réponses sont par défaut *privées* : les mutualiser entre clients est le
 *    grand classique du cache de passerelle, et c'est une fuite de session.
 *  - **Toute réponse autre que 200**, ou portant `Set-Cookie`, `Cache-Control:
 *    no-store` ou `private` — l'amont a explicitement dit non.
 *
 * ## La tension avec la mémoire constante
 *
 * Mettre en cache impose de matérialiser le corps en mémoire, ce qui est
 * exactement ce que {@see \App\Proxy\ProxyController} refuse de faire pour tenir
 * $\Delta M = 0$. Les deux propriétés sont donc conciliées par un **plafond de
 * taille** : au-delà de `shield.max_body_bytes`, la réponse traverse la
 * passerelle en flux et n'est pas mise en cache du tout. Une passerelle qui
 * mettrait tout en cache échangerait sa garantie mémoire contre un taux de hit,
 * et perdrait la seule chose que ce POC cherche à démontrer.
 *
 * `final readonly`, sans état d'instance : sûre dans la boucle worker.
 */
final readonly class ResponseCache
{
    /** Préfixe de clé — versionné pour qu'un changement de format n'empoisonne pas un cache existant. */
    private const string KEY_PREFIX = 'es1.';

    /**
     * Longueur du condensat conservée dans la clé.
     *
     * PSR-16 plafonne les clés à 64 caractères et le framework applique la
     * limite : un sha256 complet (64) plus un préfixe la dépasse. 40 caractères
     * hexadécimaux laissent 160 bits — une collision servirait la mauvaise page
     * à un client, donc la marge est prise du côté sûr plutôt qu'au plus court.
     */
    private const int KEY_HASH_LENGTH = 40;

    /** En-têtes de requête qui rendent la réponse privée par nature. */
    private const array PRIVATE_REQUEST_HEADERS = ['authorization', 'cookie'];

    public function __construct(
        private CacheInterface $cache,
        private StreamFactoryInterface $streams,
        private int $ttlSeconds = 30,
        private int $maxBodyBytes = 262_144,
    ) {}

    /**
     * Rend la réponse mise en cache pour cette requête, ou `null`.
     */
    public function lookup(ServerRequestInterface $request, ResponseInterface $template): ?ResponseInterface
    {
        if (!$this->isCacheable($request)) {
            return null;
        }

        // Frontière de désérialisation : ce que rend le cache est du `mixed`
        // tant qu'il n'a pas été prouvé. Chaque champ est donc vérifié SUR PLACE,
        // sans variable intermédiaire — une entrée mal formée (format changé,
        // backend partagé avec un autre service) doit se solder par un miss, pas
        // par une réponse à moitié reconstruite.
        $entry = $this->cache->get($this->key($request));
        if (
            !is_array($entry)
            || !is_int($entry['status'] ?? null)
            || !is_array($entry['headers'] ?? null)
            || !is_string($entry['body'] ?? null)
        ) {
            return null;
        }

        $response = $template->withStatus($entry['status'])->withBody($this->streams->createStream($entry['body']));

        foreach ($entry['headers'] as $name => $values) {
            if (!is_array($values)) {
                continue;
            }

            // Les valeurs sont filtrées, pas castées : une entrée corrompue doit
            // perdre l'en-tête douteux, jamais fabriquer une chaîne à partir de
            // n'importe quoi et la renvoyer au client comme si l'amont l'avait émise.
            $strings = array_values(array_filter($values, is_string(...)));
            if ($strings === []) {
                continue;
            }

            $response = $response->withHeader((string) $name, $strings);
        }

        // Rendre le hit observable : sans cet en-tête, le bénéfice du Shield
        // n'est mesurable qu'indirectement, au chronomètre.
        return $response->withHeader('X-EcoShield-Cache', 'HIT');
    }

    /**
     * Mémorise la réponse amont quand elle est mutualisable, et la rend
     * inchangée dans tous les cas.
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isCacheable($request) || !$this->isStorable($response)) {
            return $response->withHeader('X-EcoShield-Cache', 'BYPASS');
        }

        $stream = $response->getBody();
        $declared = $stream->getSize();

        // Taille inconnue (réponse en flux) ou trop grande : on laisse passer
        // sans jamais matérialiser le corps — la garantie mémoire prime.
        if ($declared === null || $declared > $this->maxBodyBytes) {
            return $response->withHeader('X-EcoShield-Cache', 'STREAM');
        }

        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = $stream->getContents();

        $this->cache->set(
            $this->key($request),
            [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => $body,
            ],
            $this->ttlSeconds,
        );

        // Le corps vient d'être consommé : on rend une réponse portant un flux
        // neuf, sinon l'appelant émettrait une réponse vide.
        return $response->withBody($this->streams->createStream($body))->withHeader('X-EcoShield-Cache', 'MISS');
    }

    private function isCacheable(ServerRequestInterface $request): bool
    {
        if (!in_array(mb_strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return false;
        }

        foreach (self::PRIVATE_REQUEST_HEADERS as $header) {
            if ($request->hasHeader($header)) {
                return false;
            }
        }

        return true;
    }

    private function isStorable(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 200 || $response->hasHeader('Set-Cookie')) {
            return false;
        }

        foreach ($response->getHeader('Cache-Control') as $directive) {
            $value = mb_strtolower($directive);
            if (str_contains($value, 'no-store') || str_contains($value, 'private')) {
                return false;
            }
        }

        return true;
    }

    /**
     * La clé couvre méthode + cible complète : deux requêtes ne partagent une
     * entrée que si elles demandent littéralement la même ressource.
     */
    private function key(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();

        $digest = hash('sha256', implode('|', [
            mb_strtoupper($request->getMethod()),
            $uri->getPath(),
            $uri->getQuery(),
        ]));

        return self::KEY_PREFIX . mb_substr($digest, 0, self::KEY_HASH_LENGTH);
    }
}
