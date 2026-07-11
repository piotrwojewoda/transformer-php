<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Training\TrainingJobId;
use App\Shared\Domain\DomainEvent;

// Fired when a training job fails. Carries the job id and a
// human-readable reason.
final readonly class TrainingFailed implements DomainEvent
{
    public function __construct(
        public TrainingJobId $jobId,
        public string $reason,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
