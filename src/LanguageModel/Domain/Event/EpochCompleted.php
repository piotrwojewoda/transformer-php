<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Model\ModelId;
use App\Shared\Domain\DomainEvent;

// Fired after every epoch (one full pass of training) finishes.
// Carries the epoch number and the loss, so listeners can build
// a loss-over-time graph.
final readonly class EpochCompleted implements DomainEvent
{
    public function __construct(
        public ModelId $modelId,
        public int $epoch,
        public float $loss,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
