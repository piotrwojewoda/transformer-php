<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Inference;

// Settings that say HOW the model should pick the next character
// when generating text. Two things to choose:
//   - the strategy (greedy or top-K)
//   - the maximum number of new tokens to produce
final readonly class SamplingConfig
{
    public function __construct(
        public SamplingStrategy $strategy,
        public int $maxNewTokens,
        public ?int $topK = null,
    ) {
        if ($maxNewTokens <= 0) {
            throw new \InvalidArgumentException('maxNewTokens must be > 0.');
        }
        // The topK value only makes sense with the TopK strategy.
        if ($strategy === SamplingStrategy::TopK) {
            if ($topK !== null && $topK >= 1) {
                return;
            }
            throw new \InvalidArgumentException('topK must be a positive integer when strategy is TopK.');
        }
        if ($topK === null) {
            return;
        }
        throw new \InvalidArgumentException('topK must be null when strategy is not TopK.');
    }

    /**
     * Convenience helper: build a greedy sampling config.
     */
    public static function greedy(int $maxNewTokens): self
    {
        return new self(SamplingStrategy::Greedy, $maxNewTokens);
    }

    /**
     * Convenience helper: build a top-K sampling config.
     */
    public static function topK(int $maxNewTokens, int $k): self
    {
        return new self(SamplingStrategy::TopK, $maxNewTokens, $k);
    }

    public function equals(self $other): bool
    {
        return $this->strategy === $other->strategy
            && $this->maxNewTokens === $other->maxNewTokens
            && $this->topK === $other->topK;
    }
}
