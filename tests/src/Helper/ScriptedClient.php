<?php

declare(strict_types=1);

namespace AppTests\Helper;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Waffle\Commons\HttpClient\Exception\NetworkException;

/**
 * PSR-18 transport that fails at the network level a set number of times, then
 * answers — and counts every call and keeps every body stream it was handed, so
 * a test sees what the retry policy did.
 */
final class ScriptedClient implements ClientInterface
{
    public private(set) int $calls = 0;

    /** @var list<StreamInterface> */
    public private(set) array $bodies = [];

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly int $failures = PHP_INT_MAX,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;
        $this->bodies[] = $request->getBody();
        if ($this->calls <= $this->failures) {
            throw new NetworkException($request, 'connection refused', 7);
        }

        return $this->response;
    }
}
