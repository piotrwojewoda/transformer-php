<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Clock;

use App\Shared\Domain\Clock;

// The "real" clock used in production. Every time someone asks
// for the time, we look at the system clock and return a UTC
// DateTimeImmutable (which means: time-zone-independent, so
// nothing weird happens if the server is in a different zone
// than the user).
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
