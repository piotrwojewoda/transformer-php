<?php

declare(strict_types=1);

namespace App\Shared\Domain;

// A tiny interface that just asks "what's the time?". We use it
// instead of calling new \DateTimeImmutable() directly because:
//   1. In tests we can pass a MockClock that always returns the
//      same time (or advances on demand), making tests
//      deterministic.
//   2. The whole system uses the same source of time, so we can
//      change it in one place if we ever need to.
interface Clock
{
    public function now(): \DateTimeImmutable;
}
