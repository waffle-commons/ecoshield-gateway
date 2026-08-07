<?php

declare(strict_types=1);

namespace WaffleTests\Commons\EcoshieldGateway\Helper;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Concrete PSR-18 spy — records the request the proxy actually sent upstream.
 *
 * Deliberately not a PHPUnit mock: the assertions here inspect the recorded
 * request afterwards rather than pre-declaring expectations, and an
 * expectation-less mock trips PHPUnit 12.5's "OK, but there were issues!".
 */
final class RecordingClient implements ClientInterface
{
    private ?RequestInterface $seen = null;

    public function __construct(
        private readonly ResponseInterface $response,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->seen = $request;

        return $this->response;
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->seen;
    }
}
