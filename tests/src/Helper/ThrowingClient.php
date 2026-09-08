<?php

declare(strict_types=1);

namespace AppTests\Helper;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** PSR-18 client that always fails, for the 502 path. */
final class ThrowingClient implements ClientInterface
{
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new class('upstream.internal:8080 refused the connection') extends RuntimeException implements
            ClientExceptionInterface {};
    }
}
