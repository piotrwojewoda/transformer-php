<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Training;

// A small bag of "knobs" for training. Like the recipe on a soup
// can, it says how long to cook, at what temperature, etc.
//
// The values are simple numbers because there is no behavior
// here; the trainer and message handler actually use them.
final readonly class TrainingConfig
{
    public function __construct(
        // How big each optimizer step can be. Too big and we
        // overshoot the answer; too small and training is slow.
        public float $learningRate,
        // How many times we want to run through the whole training
        // sample. (In this project, one epoch = one random window
        // and one update.)
        public int $totalEpochs,
        // How many tokens we look at in each training window.
        public int $seqLen,
        // How many windows per step. We always use 1 in this
        // project, so it's a default parameter.
        public int $batchSize = 1,
    ) {
        // Sanity checks: nonsense values get refused up front.
        if ($learningRate <= 0) {
            throw new \InvalidArgumentException('learningRate must be > 0.');
        }
        if ($totalEpochs <= 0) {
            throw new \InvalidArgumentException('totalEpochs must be > 0.');
        }
        if ($seqLen <= 0) {
            throw new \InvalidArgumentException('seqLen must be > 0.');
        }
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batchSize must be >= 1.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->learningRate === $other->learningRate
            && $this->totalEpochs === $other->totalEpochs
            && $this->seqLen === $other->seqLen
            && $this->batchSize === $other->batchSize;
    }
}
