<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Corpus\CorpusId;
use App\Shared\Domain\DomainEvent;

// Fired when text is added to a corpus (either at create time
// or via an append). Carries the number of characters added so
// listeners can show "ingested 1234 characters".
final readonly class TextIngested implements DomainEvent
{
    public function __construct(
        public CorpusId $corpusId,
        public int $length,
    ) {
        if ($length < 0) {
            throw new \InvalidArgumentException('Length must be non-negative.');
        }
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
