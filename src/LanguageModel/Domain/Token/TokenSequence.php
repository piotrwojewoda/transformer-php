<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Token;

// An IMMUTABLE list of token ids, in order. "Immutable" means we
// can never change it after creating it; any change (append, window,
// prepend) returns a NEW TokenSequence with the change applied.
// This makes the code safer because nobody can secretly modify
// someone else's sequence.
final readonly class TokenSequence
{
    /** @var list<TokenId> */
    public array $ids;

    /**
     * @param iterable<TokenId> $ids
     */
    public function __construct(iterable $ids = [])
    {
        // We turn whatever the caller passed (array, generator, ...)
        // into a plain PHP list.
        $list = [];
        foreach ($ids as $id) {
            $list[] = $id;
        }
        $this->ids = $list;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * How many tokens are in this sequence.
     */
    public function length(): int
    {
        return \count($this->ids);
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    /**
     * Read the token id at a given position. Throws if out of range.
     */
    public function at(int $index): TokenId
    {
        if (!isset($this->ids[$index])) {
            throw new \OutOfBoundsException("No TokenId at index $index.");
        }

        return $this->ids[$index];
    }

    /**
     * Take a slice from $start (inclusive) to $end (exclusive).
     * Like PHP's array_slice but for our immutable sequence.
     */
    public function window(int $start, int $end): self
    {
        if ($start < 0 || $end < $start) {
            throw new \InvalidArgumentException("Invalid window [$start, $end).");
        }
        $slice = \array_slice($this->ids, $start, $end - $start);

        return new self($slice);
    }

    /**
     * Return a new sequence with $id added at the end.
     */
    public function append(TokenId $id): self
    {
        return new self([...$this->ids, $id]);
    }

    /**
     * Return a new sequence with $id added at the beginning.
     */
    public function prepend(TokenId $id): self
    {
        return new self([$id, ...$this->ids]);
    }

    /**
     * Read the first token. Throws if the sequence is empty.
     * (Unlike the array_shift() function, this does NOT modify
     // the sequence; it just returns the first id.)
     */
    public function shift(): TokenId
    {
        if ($this->ids === []) {
            throw new \UnderflowException('Cannot shift from an empty TokenSequence.');
        }
        $first = $this->ids[0];

        return $first;
    }

    /**
     * Are these two sequences the same? Same length and same
     * token id at every position.
     */
    public function equals(self $other): bool
    {
        if (\count($this->ids) !== \count($other->ids)) {
            return false;
        }
        foreach ($this->ids as $i => $id) {
            if (!$id->equals($other->ids[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return a plain PHP array of integers (unwrapping each
     * TokenId). The math layer prefers raw ints, so this
     * conversion is handy.
     *
     * @return list<int>
     */
    public function toIntArray(): array
    {
        return array_map(static fn (TokenId $id) => $id->value, $this->ids);
    }
}
