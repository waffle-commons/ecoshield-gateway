<?php

declare(strict_types=1);

namespace AppTests\Helper;

use Psr\Log\NullLogger;

/**
 * PSR-3 logger that keeps the warnings and drops everything else — so a test sees
 * that a degraded path said so, without a PHPUnit mock.
 */
final class WarningRecorder extends NullLogger
{
    /** @var list<string> */
    public private(set) array $warnings = [];

    #[\Override]
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
    }
}
