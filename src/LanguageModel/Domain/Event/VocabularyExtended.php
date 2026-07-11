<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Event;

use App\LanguageModel\Domain\Corpus\CorpusId;
use App\Shared\Domain\DomainEvent;

// Fired when a new character is added to a vocabulary. Carries
// the new token id and the character, so listeners (like a UI
// preview) can react.
final readonly class VocabularyExtended implements DomainEvent
{
    public function __construct(
        public CorpusId $corpusId,
        public int $tokenId,
        public string $character,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
