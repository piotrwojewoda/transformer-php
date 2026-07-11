<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Training;

// A small wrapper around a single float: the loss after an
// epoch. The "loss" is the model's report card -- a number that
// says "how wrong am I?". Lower is better.
//
// We wrap it in a class so we can guarantee two important
// properties:
//   1. It must be a real, finite number (no NaN, no infinity).
//   2. It must be non-negative (a loss can't be negative).
final readonly class TrainingLoss
{
    public function __construct(public float $value)
    {
        if (!\is_finite($value)) {
            throw new \InvalidArgumentException("TrainingLoss must be finite, got: $value");
        }
        if ($value < 0) {
            throw new \InvalidArgumentException("TrainingLoss must be >= 0, got: $value");
        }
    }

    /**
     * Are two losses "equal enough"? Allows a tiny floating-point
     * tolerance, because math has rounding.
     */
    public function equals(self $other, float $eps = 1e-9): bool
    {
        return \abs($this->value - $other->value) < $eps;
    }

    public function __toString(): string
    {
        return number_format($this->value, 6);
    }
}
