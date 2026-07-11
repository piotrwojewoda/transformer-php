<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Model\ModelId;
use App\Shared\Domain\DomainEvent;

// Fired when a model finishes training successfully. Carries the
// total number of epochs and the final loss so listeners (e.g.
// the UI) can show "trained for 50 epochs, final loss 1.23".
final readonly class ModelTrained implements DomainEvent
{
    public function __construct(
        public ModelId $modelId,
        public int $totalEpochs,
        public float $finalLoss,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
