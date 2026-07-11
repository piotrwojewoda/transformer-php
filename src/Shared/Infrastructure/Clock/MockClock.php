<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Clock;

use App\Shared\Domain\Clock;

// A test-only clock that does NOT look at the real time. It
// starts at a fixed moment and only changes when the test code
// explicitly asks it to. This makes tests deterministic: every
// run produces the same "now" and we can compare results
// exactly.
final class MockClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $now = null)
    {
        // Default to a known, fixed time if the test doesn't say.
        $this->now = $now ?? new \DateTimeImmutable('2025-01-01T00:00:00Z');
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Jump to a specific time.
     */
    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    /**
     * Move forward by a PHP-style interval like "1 hour" or
     // "2 days". Useful for simulating "time passed".
     */
    public function advance(string $interval): void
    {
        $this->now = $this->now->modify('+'.$interval);
    }
}
