<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Inference\PredictionId;
use App\Shared\Domain\DomainEvent;

// Fired when a prediction finishes successfully. Carries the
// prediction id and the text that was generated.
final readonly class PredictionGenerated implements DomainEvent
{
    public function __construct(
        public PredictionId $predictionId,
        public string $generatedText,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
