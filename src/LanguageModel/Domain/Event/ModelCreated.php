<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\ModelId;
use App\Shared\Domain\DomainEvent;

// A "domain event" is a small message saying "something important
// happened in the system". Other parts of the system can listen
// for these events and react (e.g. update a search index, send
// an email, write to a log).
//
// This event says: a new model was just created.
final readonly class ModelCreated implements DomainEvent
{
    public function __construct(
        public ModelId $modelId,   // which model
        public ModelConfig $config, // how it's shaped
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
