<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Model\ModelId;
use App\Shared\Domain\DomainEvent;

// Fired when something goes wrong with a model. Carries a
// human-readable reason that we can show in the UI or log.
final readonly class ModelFailed implements DomainEvent
{
    public function __construct(
        public ModelId $modelId,
        public string $reason,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
