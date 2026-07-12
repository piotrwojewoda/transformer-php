<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Category;

use Symfony\Component\Uid\Uuid;

// Unique id for a Category. Same reasons as CorpusId: uniqueness,
// time-ordering.
final readonly class CategoryId
{
    public function __construct(public string $value)
    {
        if (!Uuid::isValid($this->value)) {
            throw new \InvalidArgumentException('CategoryId must be a valid UUID, got: '.$this->value);
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
