<?php

declare(strict_types=1);

namespace App\Factory;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitBreakerPolicy;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitStateStorageInterface;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\HttpClient\RetryPolicy;
use Waffle\Commons\Contracts\HttpClient\TimeoutPolicy;
use Waffle\Commons\Data\CircuitBreaker\RedisCircuitStateStorage;
use Waffle\Commons\HttpClient\Client;
use Waffle\Commons\HttpClient\Resilience\CircuitBreakerClient;
use Waffle\Commons\HttpClient\Resilience\CircuitObservers;
use Waffle\Commons\HttpClient\Resilience\RetryingClient;

/**
 * Le client vers le monolithe (NET-02/03, RFC-024) :
 * `RetryingClient(CircuitBreakerClient(Client))`.
 *
 * Chaque tentative repasse par le disjoncteur, dont le refus n'est jamais
 * retenté, et aucun transfert n'a de délai infini. Les politiques viennent de
 * `waffle.http_client.resilience.*` et `waffle.http_client.breaker.*` (app.yaml),
 * les clés du squelette du framework ; une clé absente prend la valeur par défaut
 * du contrat. L'état du disjoncteur vit dans Redis : tous les workers, et toutes
 * les instances, voient le même circuit.
 *
 * Une passerelle ne rejoue JAMAIS une méthode non idempotente : elle ne connaît
 * pas la sémantique de l'amont, et un POST rejoué peut dupliquer une commande.
 * `retry_non_idempotent` n'est donc pas lue, quoi qu'en dise la configuration.
 */
final class UpstreamClientFactory
{
    private const string RESILIENCE = 'waffle.http_client.resilience.';

    private const string BREAKER = 'waffle.http_client.breaker.';

    /** Préfixe des circuits dans Redis, à côté du `ecoshield:` du Shield. */
    private const string CIRCUIT_PREFIX = 'ecoshield:circuit:';

    /**
     * Le client amont tel que la passerelle l'utilise. Le disjoncteur n'est posé
     * que si `waffle.http_client.breaker.enabled` est vrai.
     */
    public static function create(
        ConfigInterface $config,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        LoggerInterface $logger,
    ): RetryingClient {
        $storage = $config->getBool(self::BREAKER . 'enabled') === true ? self::circuitStorage($config) : null;

        return self::resilient(
            new Client($responseFactory, $streamFactory)->withTimeouts(self::timeouts($config)),
            $config,
            $storage,
            $logger,
        );
    }

    /**
     * Enveloppe `$transport` dans les reprises et, avec un stockage, dans le
     * disjoncteur. Une panne du stockage laisse passer, avec un avertissement.
     */
    public static function resilient(
        ClientInterface $transport,
        ConfigInterface $config,
        ?CircuitStateStorageInterface $storage,
        LoggerInterface $logger,
    ): RetryingClient {
        if ($storage !== null) {
            $transport = new CircuitBreakerClient(
                $transport,
                $storage,
                self::breakerPolicy($config),
                new CircuitObservers(logger: $logger),
            );
        }

        return new RetryingClient($transport, self::retryPolicy($config));
    }

    /** Les délais d'un transfert : jamais infinis, bornés par le contrat. */
    public static function timeouts(ConfigInterface $config): TimeoutPolicy
    {
        return new TimeoutPolicy(
            $config->getInt(self::RESILIENCE . 'connect_timeout_ms') ?? TimeoutPolicy::DEFAULT_CONNECT_MS,
            $config->getInt(self::RESILIENCE . 'total_timeout_ms') ?? TimeoutPolicy::DEFAULT_TOTAL_MS,
        );
    }

    public static function retryPolicy(ConfigInterface $config): RetryPolicy
    {
        $defaults = new RetryPolicy();

        return new RetryPolicy(
            maxAttempts: $config->getInt(self::RESILIENCE . 'max_attempts') ?? $defaults->maxAttempts,
            baseDelayMs: $config->getInt(self::RESILIENCE . 'base_delay_ms') ?? $defaults->baseDelayMs,
            maxDelayMs: $config->getInt(self::RESILIENCE . 'max_delay_ms') ?? $defaults->maxDelayMs,
            budgetMs: $config->getInt(self::RESILIENCE . 'budget_ms') ?? $defaults->budgetMs,
            retryNonIdempotent: false,
        );
    }

    public static function breakerPolicy(ConfigInterface $config): CircuitBreakerPolicy
    {
        $defaults = new CircuitBreakerPolicy();

        return new CircuitBreakerPolicy(
            $config->getInt(self::BREAKER . 'failure_rate_percent') ?? $defaults->failureRatePercent,
            $config->getInt(self::BREAKER . 'minimum_calls') ?? $defaults->minimumCalls,
            $config->getInt(self::BREAKER . 'window_seconds') ?? $defaults->windowSeconds,
            $config->getInt(self::BREAKER . 'cool_down_seconds') ?? $defaults->coolDownSeconds,
            $config->getInt(self::BREAKER . 'half_open_trials') ?? $defaults->halfOpenTrials,
        );
    }

    /** L'état partagé du circuit, dans le Redis de `waffle.http_client.breaker.redis_dsn`. */
    public static function circuitStorage(ConfigInterface $config): RedisCircuitStateStorage
    {
        return new RedisCircuitStateStorage(
            RedisPoolFactory::create($config->getString(self::BREAKER . 'redis_dsn') ?? 'redis://redis:6379'),
            self::CIRCUIT_PREFIX,
        );
    }
}
