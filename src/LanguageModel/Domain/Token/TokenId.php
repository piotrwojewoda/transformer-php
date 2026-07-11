<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Token;

// A simple wrapper around a single integer. The integer is the
// "id" of a token inside the vocabulary. We wrap it in a class
// so we can't accidentally mix it up with other integers.
final readonly class TokenId
{
    public function __construct(public int $value)
    {
        // Token ids start at 0 (the <pad> token) and go up. We refuse
        // negative values because they would have no meaning.
        if ($value < 0) {
            throw new \InvalidArgumentException('TokenId must be non-negative, got: '.$value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
