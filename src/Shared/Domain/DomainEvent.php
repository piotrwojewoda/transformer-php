<?php

declare(strict_types=1);

namespace App\Shared\Domain;

// A "domain event" is a small message that says "something
// important happened in the business". Every event knows when
// it happened (occurredAt) so they can be sorted and stored.
interface DomainEvent
{
    public function occurredAt(): \DateTimeImmutable;
}
