<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Model;

// This is a small "settings" object that describes the shape of a
// model. Think of it as a recipe card: it doesn't contain the
// model itself, just the sizes and counts that define it.
//
// Each setting is a number that controls how big the model is in
// a different direction. Bigger numbers = more powerful but slower.
final readonly class ModelConfig
{
    public function __construct(
        // The width of every token vector. Bigger = the model can
        // remember more about each position. Like the number of
        // columns in every spreadsheet in the model.
        public int $dModel,
        // How many attention heads to split each layer into. (In
        // this project we always use 1 head, so this is mostly a
        // placeholder for future multi-head support.)
        public int $numHeads,
        // How many attention+FFN blocks to stack on top of each
        // other. More layers = the model can learn more complex
        // patterns, but training gets harder.
        public int $numLayers,
        // The width of the "hidden" layer inside each FFN. Usually
        // 2-4 times dModel. Bigger = more capacity in the FFN.
        public int $dFf,
        // The longest sentence the model can read. Beyond this we
        // have to chop the input into pieces.
        public int $maxSeqLen,
        // How many different tokens exist in the vocabulary. The
        // first 3 ids are reserved for special tokens.
        public int $vocabSize,
    ) {
        // Sanity checks. The numbers must be positive and the
        // relationships between them must make sense.
        if ($dModel <= 0 || $numHeads <= 0 || $numLayers <= 0 || $dFf <= 0 || $maxSeqLen <= 0 || $vocabSize <= 0) {
            throw new \InvalidArgumentException('All ModelConfig dimensions must be positive.');
        }
        // dModel must split evenly across heads. We can't slice a
        // pizza of size 10 into 3 equal pieces.
        if ($dModel % $numHeads !== 0) {
            throw new \InvalidArgumentException("dModel ({$dModel}) must be divisible by numHeads ({$numHeads}).");
        }
        // We reserve 3 token ids for <pad>, <bos>, <unk> and need at
        // least one real character, so vocabSize must be at least 4.
        if ($vocabSize < 4) {
            throw new \InvalidArgumentException('vocabSize must be >= 4 (3 reserved + at least 1 user).');
        }
    }

    /**
     * The width of one attention head. dModel split across numHeads.
     */
    public function dHead(): int
    {
        return intdiv($this->dModel, $this->numHeads);
    }

    public function equals(self $other): bool
    {
        return $this->dModel === $other->dModel
            && $this->numHeads === $other->numHeads
            && $this->numLayers === $other->numLayers
            && $this->dFf === $other->dFf
            && $this->maxSeqLen === $other->maxSeqLen
            && $this->vocabSize === $other->vocabSize;
    }

    /**
     * How many numbers are stored in the attention weights.
     * Per layer: 4 * dModel * dModel (Wq, Wk, Wv, Wo).
     */
    public function totalAttentionParams(): int
    {
        return 4 * $this->dModel * $this->dModel * $this->numLayers;
    }

    /**
     * How many numbers are stored in the FFN weights.
     * Per layer: dModel * dFf + dFf + dFf * dModel + dModel.
     * That's W1, b1, W2, b2.
     */
    public function totalFfnParams(): int
    {
        $perLayer = $this->dModel * $this->dFf + $this->dFf + $this->dFf * $this->dModel + $this->dModel;

        return $perLayer * $this->numLayers;
    }

    /**
     * How many numbers are in the embedding tables.
     * (vocabSize + maxSeqLen) * dModel.
     */
    public function totalEmbeddingParams(): int
    {
        return $this->vocabSize * $this->dModel + $this->maxSeqLen * $this->dModel;
    }
}
