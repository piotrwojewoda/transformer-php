<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Training;

use Symfony\Component\Uid\Uuid;

// A unique identifier for a training job, just like ModelId.
// It uses UUIDv7 so different jobs are easy to tell apart and
// the database can sort them by creation time automatically.
final readonly class TrainingJobId
{
    public function __construct(public string $value)
    {
        if (!Uuid::isValid($this->value)) {
            throw new \InvalidArgumentException('TrainingJobId must be a valid UUID, got: '.$this->value);
        }
    }

    /**
     * Create a fresh, time-sortable id.
     */
    public static function create(): self
    {
        return new self((string) Uuid::v7());
    }

    /**
     * Wrap an existing id (e.g. one from a URL).
     */
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
