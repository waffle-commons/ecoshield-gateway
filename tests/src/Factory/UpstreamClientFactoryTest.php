<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\UpstreamClientFactory;
use AppTests\Helper\ScriptedClient;
use AppTests\Helper\StubCircuitStorage;
use AppTests\Helper\StubConfig;
use AppTests\Helper\TestRedis;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use Redis;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitBreakerPolicy;
use Waffle\Commons\Contracts\CircuitBreaker\Enum\CircuitState;
use Waffle\Commons\Contracts\HttpClient\RetryPolicy;
use Waffle\Commons\Contracts\HttpClient\TimeoutPolicy;
use Waffle\Commons\Http\Factory\RequestFactory;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\StreamFactory;
use Waffle\Commons\Http\Response;
use Waffle\Commons\HttpClient\Exception\CircuitOpenException;
use Waffle\Commons\HttpClient\Exception\NetworkException;
use Waffle\Commons\HttpClient\Resilience\RetryingClient;

/**
 * Le client amont (NET-02/03) : délais bornés, reprises des seules méthodes
 * idempotentes, disjoncteur partagé — et jamais de POST rejoué, quoi qu'en dise
 * la configuration.
 */
#[CoversClass(UpstreamClientFactory::class)]
final class UpstreamClientFactoryTest extends TestCase
{
    /** Reculs d'une milliseconde : c'est la politique qui est vérifiée, pas l'horloge. */
    private const array FAST = [
        'waffle.http_client.resilience.base_delay_ms' => '1',
        'waffle.http_client.resilience.max_delay_ms' => '1',
    ];

    #[Test]
    public function an_idempotent_request_is_retried_up_to_the_policy(): void
    {
        $transport = new ScriptedClient(new Response(statusCode: 200));
        $client = UpstreamClientFactory::resilient($transport, new StubConfig(self::FAST), null, new NullLogger());

        try {
            $client->sendRequest(self::request('GET'));
            self::fail('Un amont toujours injoignable doit finir par lever.');
        } catch (NetworkException) {
            self::assertSame(3, $transport->calls);
        }
    }

    #[Test]
    public function a_transient_failure_is_absorbed(): void
    {
        $transport = new ScriptedClient(new Response(statusCode: 200), failures: 1);
        $client = UpstreamClientFactory::resilient($transport, new StubConfig(self::FAST), null, new NullLogger());

        self::assertSame(200, $client->sendRequest(self::request('PUT'))->getStatusCode());
        self::assertSame(2, $transport->calls);
    }

    #[Test]
    public function a_non_idempotent_request_is_never_replayed_even_when_the_configuration_asks(): void
    {
        // Une passerelle ignore la sémantique de l'amont : rejouer un POST peut
        // dupliquer une commande. La clé est donc ignorée, pas seulement absente.
        $config = new StubConfig([...self::FAST, 'waffle.http_client.resilience.retry_non_idempotent' => 'true']);

        foreach (['POST', 'PATCH'] as $method) {
            $transport = new ScriptedClient(new Response(statusCode: 200));
            $client = UpstreamClientFactory::resilient($transport, $config, null, new NullLogger());

            try {
                $client->sendRequest(self::request($method));
                self::fail('Un amont injoignable doit lever.');
            } catch (NetworkException) {
                self::assertSame(1, $transport->calls, $method . ' ne doit partir qu\'une fois');
            }
        }
    }

    #[Test]
    public function an_open_circuit_fails_fast_and_is_not_retried(): void
    {
        $transport = new ScriptedClient(new Response(statusCode: 200), failures: 0);
        $storage = new StubCircuitStorage(allow: false);
        $client = UpstreamClientFactory::resilient($transport, new StubConfig(self::FAST), $storage, new NullLogger());

        try {
            $client->sendRequest(self::request('GET'));
            self::fail('Un circuit ouvert doit refuser l\'appel.');
        } catch (CircuitOpenException) {
            self::assertSame(0, $transport->calls, 'le monolithe ne doit pas être appelé');
            self::assertSame(1, $storage->acquired, 'un refus du disjoncteur ne se retente pas');
        }
    }

    #[Test]
    public function every_attempt_goes_through_the_breaker(): void
    {
        $transport = new ScriptedClient(new Response(statusCode: 200), failures: 1);
        $storage = new StubCircuitStorage();
        $client = UpstreamClientFactory::resilient($transport, new StubConfig(self::FAST), $storage, new NullLogger());

        $client->sendRequest(self::request('GET'));

        // L'échec puis le succès : deux autorisations demandées, deux issues consignées.
        self::assertSame(2, $storage->acquired);
        self::assertSame(2, $storage->recorded);
    }

    #[Test]
    public function an_unreachable_circuit_storage_lets_the_call_through(): void
    {
        $transport = new ScriptedClient(new Response(statusCode: 200), failures: 0);
        $client = UpstreamClientFactory::resilient(
            $transport,
            new StubConfig(self::FAST),
            new StubCircuitStorage(down: true),
            new NullLogger(),
        );

        self::assertSame(200, $client->sendRequest(self::request('GET'))->getStatusCode());
        self::assertSame(1, $transport->calls);
    }

    #[Test]
    public function the_policies_come_from_the_configuration(): void
    {
        $config = new StubConfig([
            'waffle.http_client.resilience.connect_timeout_ms' => '250',
            'waffle.http_client.resilience.total_timeout_ms' => '4000',
            'waffle.http_client.resilience.max_attempts' => '2',
            'waffle.http_client.resilience.base_delay_ms' => '50',
            'waffle.http_client.resilience.max_delay_ms' => '400',
            'waffle.http_client.resilience.budget_ms' => '6000',
            'waffle.http_client.breaker.failure_rate_percent' => '25',
            'waffle.http_client.breaker.minimum_calls' => '4',
            'waffle.http_client.breaker.window_seconds' => '20',
            'waffle.http_client.breaker.cool_down_seconds' => '5',
            'waffle.http_client.breaker.half_open_trials' => '2',
        ]);

        $timeouts = UpstreamClientFactory::timeouts($config);
        self::assertSame([250, 4000], [$timeouts->connectMs, $timeouts->totalMs]);

        $retry = UpstreamClientFactory::retryPolicy($config);
        self::assertSame([2, 50, 400, 6000, false], [
            $retry->maxAttempts,
            $retry->baseDelayMs,
            $retry->maxDelayMs,
            $retry->budgetMs,
            $retry->retryNonIdempotent,
        ]);

        $breaker = UpstreamClientFactory::breakerPolicy($config);
        self::assertSame([25, 4, 20, 5, 2], [
            $breaker->failureRatePercent,
            $breaker->minimumCalls,
            $breaker->windowSeconds,
            $breaker->coolDownSeconds,
            $breaker->halfOpenTrials,
        ]);
    }

    #[Test]
    public function an_absent_key_takes_the_contract_default(): void
    {
        $config = new StubConfig();

        self::assertEquals(new TimeoutPolicy(), UpstreamClientFactory::timeouts($config));
        self::assertEquals(new RetryPolicy(), UpstreamClientFactory::retryPolicy($config));
        self::assertEquals(new CircuitBreakerPolicy(), UpstreamClientFactory::breakerPolicy($config));
    }

    #[Test]
    public function the_breaker_storage_is_built_only_when_the_breaker_is_enabled(): void
    {
        $unreadable = ['waffle.http_client.breaker.redis_dsn' => 'redis://redis:99999'];

        // Disjoncteur absent : son DSN n'est même pas lu.
        self::assertInstanceOf(RetryingClient::class, UpstreamClientFactory::create(
            new StubConfig($unreadable),
            new ResponseFactory(),
            new StreamFactory(),
            new NullLogger(),
        ));

        $this->expectException(InvalidArgumentException::class);
        UpstreamClientFactory::create(
            new StubConfig([...$unreadable, 'waffle.http_client.breaker.enabled' => 'true']),
            new ResponseFactory(),
            new StreamFactory(),
            new NullLogger(),
        );
    }

    #[Test]
    public function the_circuit_lives_in_redis_under_the_gateway_prefix(): void
    {
        $storage = UpstreamClientFactory::circuitStorage(new StubConfig([
            'waffle.http_client.breaker.redis_dsn' => TestRedis::dsn(),
        ]));
        // Une origine neuve à chaque exécution : aucun état hérité d'un passage précédent.
        $circuit = 'http://test-' . bin2hex(random_bytes(6)) . ':80';

        $decision = $storage->acquire($circuit, new CircuitBreakerPolicy());
        self::assertTrue($decision->allowed);
        self::assertSame(CircuitState::Closed, $decision->state);

        $storage->record($circuit, new CircuitBreakerPolicy(), true);

        $parts = parse_url(TestRedis::dsn());
        self::assertIsArray($parts);
        $redis = new Redis();
        $redis->connect($parts['host'] ?? 'waffle-redis', $parts['port'] ?? 6379, 1.0);
        self::assertSame(1, $redis->exists('ecoshield:circuit:' . $circuit));
        $redis->del('ecoshield:circuit:' . $circuit);
    }

    private static function request(string $method): RequestInterface
    {
        return new RequestFactory()->createRequest($method, 'http://legacy/orders/1');
    }
}
