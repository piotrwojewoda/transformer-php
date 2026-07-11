<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Model\ModelId;
use App\Shared\Domain\DomainEvent;

// Fired the moment a worker actually starts training a model
// (i.e. the model transitions Ready -> Training).
final readonly class TrainingStarted implements DomainEvent
{
    public function __construct(public ModelId $modelId)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
