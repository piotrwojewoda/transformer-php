<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Inference\PredictionId;
use App\Shared\Domain\DomainEvent;

// Fired when a prediction fails. Carries the prediction id and
// a human-readable reason.
final readonly class PredictionFailed implements DomainEvent
{
    public function __construct(
        public PredictionId $predictionId,
        public string $reason,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
