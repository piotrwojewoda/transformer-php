<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Training\TrainingJobId;
use App\Shared\Domain\DomainEvent;

// Fired when a new training job is created and added to the
// queue. Carries the job id, the model id, and the planned
// number of epochs.
final readonly class TrainingQueued implements DomainEvent
{
    public function __construct(
        public TrainingJobId $jobId,
        public ModelId $modelId,
        public int $totalEpochs,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
