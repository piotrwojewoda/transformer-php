<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Corpus;

use Symfony\Component\Uid\Uuid;

// Unique id for a Corpus, just like ModelId and TrainingJobId.
// We use UUIDv7 for the same reasons: uniqueness, time-ordering.
final readonly class CorpusId
{
    public function __construct(public string $value)
    {
        if (!Uuid::isValid($this->value)) {
            throw new \InvalidArgumentException('CorpusId must be a valid UUID, got: '.$this->value);
        }
    }

    public static function create(): self
    {
        return new self((string) Uuid::v7());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
