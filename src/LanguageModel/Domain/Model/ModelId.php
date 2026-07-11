<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Model;

use Symfony\Component\Uid\Uuid;

// A "value object" that wraps a unique identifier for a model.
// We use UUIDs (specifically version 7) because they are globally
// unique and time-sortable: a later-created model will have a
// later id, which is nice for ordering in the database.
final readonly class ModelId
{
    public function __construct(public string $value)
    {
        // Refuse anything that doesn't look like a UUID. Catching
        // mistakes early avoids weird database errors later.
        if (!Uuid::isValid($this->value)) {
            throw new \InvalidArgumentException('ModelId must be a valid UUID, got: '.$this->value);
        }
    }

    /**
     * Create a brand-new id. v7 includes a timestamp, so ids are
     * roughly sortable by creation time.
     */
    public static function create(): self
    {
        return new self((string) Uuid::v7());
    }

    /**
     * Build an id from a string we got from somewhere (e.g. the URL).
     * Will throw if the string isn't a valid UUID.
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
