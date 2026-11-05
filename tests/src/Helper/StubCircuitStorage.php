<?php

declare(strict_types=1);

namespace AppTests\Helper;

use Waffle\Commons\Contracts\CircuitBreaker\CircuitBreakerPolicy;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitDecision;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitStateStorageInterface;
use Waffle\Commons\Contracts\CircuitBreaker\Enum\CircuitState;
use Waffle\Commons\Data\RateLimit\StateStorageException;

/**
 * Circuit storage with a fixed answer — always closed, always open, or down —
 * that counts how often the breaker asked and recorded.
 */
final class StubCircuitStorage implements CircuitStateStorageInterface
{
    public private(set) int $acquired = 0;

    public private(set) int $recorded = 0;

    public function __construct(
        private readonly bool $allow = true,
        private readonly bool $down = false,
    ) {}

    #[\Override]
    public function acquire(string $circuit, CircuitBreakerPolicy $policy): CircuitDecision
    {
        $this->acquired++;
        if ($this->down) {
            throw StateStorageException::unexpectedReply();
        }

        return new CircuitDecision($this->allow, $this->allow ? CircuitState::Closed : CircuitState::Open);
    }

    #[\Override]
    public function record(string $circuit, CircuitBreakerPolicy $policy, bool $success): CircuitDecision
    {
        $this->recorded++;
        if ($this->down) {
            throw StateStorageException::unexpectedReply();
        }

        return new CircuitDecision(true, CircuitState::Closed);
    }
}
