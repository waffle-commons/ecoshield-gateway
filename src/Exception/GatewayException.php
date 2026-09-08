<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when a request cannot be proxied safely.
 *
 * Carries an HTTP status so the error-handling middleware renders the right
 * response without the gateway reaching for a renderer itself: 400 when the
 * inbound message is malformed or ambiguous (a smuggling attempt looks exactly
 * like this), 502 when the upstream is unreachable or answers unintelligibly.
 *
 * The message is deliberately terse — it reaches the client. Diagnostic detail
 * belongs in the logged previous exception, never in the proxy's own response,
 * which would otherwise become an oracle describing the internal topology.
 */
final class GatewayException extends RuntimeException
{
    public static function ambiguousFraming(): self
    {
        return new self('Bad Request: ambiguous message framing.', 400);
    }

    public static function invalidTarget(string $reason): self
    {
        return new self('Bad Request: ' . $reason, 400);
    }

    public static function upstreamUnreachable(Throwable $previous): self
    {
        return new self('Bad Gateway: upstream request failed.', 502, $previous);
    }
}
