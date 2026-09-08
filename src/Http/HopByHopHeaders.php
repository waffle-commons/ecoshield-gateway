<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\MessageInterface;

/**
 * Hop-by-hop header filtering (RFC 9110 §7.6.1, RFC 9112 §9.6).
 *
 * A proxy terminates one connection and opens another; headers that describe
 * the *connection* rather than the *message* must not cross that boundary.
 * Forwarding `Connection`, `Keep-Alive` or `Transfer-Encoding` upstream lets a
 * client dictate the framing of a hop it does not own, which is the raw
 * material of request-smuggling and connection-poisoning attacks.
 *
 * Two categories are stripped:
 *
 *  1. The **fixed** set below, enumerated by the RFC.
 *  2. Every field **named in the `Connection` header of the message itself** —
 *     the extension mechanism the RFC defines. Omitting this second step is the
 *     common bug: a request carrying `Connection: X-Internal-Auth` expects that
 *     header to die at the hop, and a proxy that forwards it anyway leaks it to
 *     the upstream.
 *
 * Stateless by construction (all-static, no instance state) — safe for the
 * FrankenPHP worker loop.
 */
final class HopByHopHeaders
{
    /**
     * Connection-scoped fields enumerated by RFC 9110 / RFC 9112.
     *
     * `Proxy-Connection` is not in the RFC — it is a non-standard field emitted
     * by older clients that behaves as hop-by-hop in practice, so it is stripped
     * on the same grounds.
     */
    private const array FIXED = [
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'proxy-connection',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    /**
     * Returns the message's headers with every hop-by-hop field removed.
     *
     * Header names are compared case-insensitively (PSR-7 preserves the original
     * case but treats names case-insensitively, as HTTP requires).
     *
     * Keys are `array-key`, not `string`, and deliberately so: PHP coerces a
     * numeric array key, so a (legal, if perverse) header named `123` arrives as
     * an int. Callers normalise with a `(string)` cast rather than assuming.
     *
     * @return array<array-key, list<string>>
     */
    public static function strip(MessageInterface $message): array
    {
        $drop = self::FIXED;

        // RFC 9110 §7.6.1: the Connection header lists ADDITIONAL field names
        // that are themselves hop-by-hop for this message only.
        foreach ($message->getHeader('Connection') as $value) {
            foreach (explode(',', $value) as $named) {
                $named = mb_strtolower(mb_trim($named));
                if ($named !== '') {
                    $drop[] = $named;
                }
            }
        }

        $kept = [];
        foreach ($message->getHeaders() as $name => $values) {
            if (in_array(mb_strtolower((string) $name), $drop, true)) {
                continue;
            }

            $kept[$name] = array_values($values);
        }

        return $kept;
    }
}
