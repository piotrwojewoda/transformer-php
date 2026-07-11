<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Transformer;

/**
 * Lookup-table embedding layer.
 *
 * WHAT IS THIS?
 * --------------
 * A neural network can't work directly with letters like 'a', 'b', 'c'.
 * It only understands numbers. So we turn every character (or "token")
 * into a list of numbers called an "embedding vector". A character
 * 'a' might become [0.1, -0.3, 0.7, ...] and 'b' becomes [-0.2, 0.5,
 * 0.1, ...]. The model learns these vectors during training so that
 * similar characters end up with similar numbers.
 *
 * HOW IT WORKS:
 * --------------
 * We keep one big table W with one row per token in the vocabulary
 * and dModel columns. To turn a token id into a vector, we just look
 * up its row: output[t, :] = W[tokenIds[t], :].
 *
 * This is basically a dictionary: "give me row number 5, please".
 *
 * Forward:  output[t, d] = W[tokenIds[t], d]   for t in [0, T)
 * Backward: dW[tokenId, d] += sum over positions t of dOut[t, d]
 *           for t where tokenIds[t] == tokenId
 *
 * The output is a Tensor of shape (T, dModel) where T is the
 * sequence length and dModel is the embedding dimension.
 */
final class EmbeddingLayer
{
    // The last input we saw, kept so backward() can use it. We don't
    // really need it for the math but we store it for debugging /
    // sanity checks.
    private ?Tensor $lastInput = null;
    // The last output we produced (same shape as the input tokens x
    // dModel). Useful for tests.
    private ?Tensor $lastOutput = null;
    // The gradient of the loss with respect to each weight in the
    // table. We accumulate this during backward() and the trainer
    // uses it to update the weights.
    private ?Tensor $weightGrad = null;

    public function __construct(
        public readonly int $vocabSize, // how many different tokens we know
        public readonly int $dModel,    // how many numbers represent each token
    ) {
    }

    /**
     * Initialise the weight matrix with N(0, 1/sqrt(dModel)).
     *
     * WHY NORMAL DISTRIBUTION?
     * We want starting weights that are small and random. If we used
     * zeros, every token would start as the same vector and the model
     * couldn't learn differences. If we used huge numbers, the math
     * would explode.
     *
     * WHY DIVIDE BY sqrt(dModel)?
     * This is a classic trick called "He / Xavier-ish init". The
     * idea is: bigger vectors should have smaller numbers, so the
     * total magnitude stays similar regardless of the model size.
     *
     * BOX-MULLER TRICK:
     * We need random numbers from a bell curve (normal distribution).
     * PHP gives us uniform random numbers (0..1). The Box-Muller
     * formula is a clever way to turn two uniform numbers into one
     * bell-curve number.
     *
     * @return Tensor (vocabSize, dModel) the random starting table
     */
    public function initWeights(RandomGenerator $rng): Tensor
    {
        // Standard deviation: 1 / sqrt(dModel). Smaller for bigger models.
        $std = 1.0 / \sqrt($this->dModel);
        $data = [];
        for ($i = 0; $i < $this->vocabSize * $this->dModel; $i++) {
            // Two uniform random numbers, both in (0, 1].
            $u1 = max(1e-12, $rng->nextFloat());
            $u2 = $rng->nextFloat();
            // Box-Muller: turn (u1, u2) into one normal-distributed number.
            // We only need the cosine half of the formula.
            $z = \sqrt(-2.0 * \log($u1)) * \cos(2.0 * M_PI * $u2);
            $data[] = $z * $std;
        }

        return new Tensor($this->vocabSize, $this->dModel, $data);
    }

    /**
     * The forward pass: turn token ids into vectors.
     *
     * WALKTHROUGH:
     * Imagine W is a phone book of size (vocabSize x dModel). For
     * every token in our input sentence, we look up its row and
     * copy it. We do this in a big loop: for each position t, copy
     * the dModel numbers from W[id, :] to output[t, :].
     *
     * @param list<int> $tokenIds the sentence, as token numbers
     * @param Tensor $weights the embedding table W
     * @return Tensor of shape (T, dModel) the vectors for each token
     */
    public function forward(array $tokenIds, Tensor $weights): Tensor
    {
        // T is how long the sentence is.
        $T = \count($tokenIds);
        // Make an empty output grid of the right size.
        $out = array_fill(0, $T * $this->dModel, 0.0);
        for ($t = 0; $t < $T; $t++) {
            // Which row of the table do we need?
            $id = $tokenIds[$t];
            // Copy that row into the output.
            for ($d = 0; $d < $this->dModel; $d++) {
                $out[$t * $this->dModel + $d] = $weights->data()[$id * $this->dModel + $d];
            }
        }
        $output = new Tensor($T, $this->dModel, $out);

        // Save what we did, in case backward() or tests want to see it.
        $this->lastInput = new Tensor($T, 1, $tokenIds);
        $this->lastOutput = $output;
        // Start the gradient table at all zeros. We'll add to it in backward().
        $this->weightGrad = Tensor::zeros($this->vocabSize, $this->dModel);

        return $output;
    }

    /**
     * The backward pass: figure out how each weight should change.
     *
     * SIMPLE IDEA:
     * During forward, we just copied rows from W. So during backward,
     * we just add the gradient coming back to whichever row of W was
     * used. If the token 'a' (id 5) was used 3 times in our sentence,
     * we add the 3 incoming gradients to row 5.
     *
     * This is called "scatter-add": we scatter the gradients onto the
     * matching rows.
     *
     * @param list<int> $tokenIds the same ids used in forward
     * @param Tensor $dOut the gradient flowing back from the next layer
     */
    public function backward(array $tokenIds, Tensor $dOut): Tensor
    {
        // If someone calls backward() before forward(), we have no
        // gradient slot to write to. Refuse.
        if ($this->weightGrad === null) {
            throw new \RuntimeException('EmbeddingLayer::backward called before forward.');
        }
        $T = \count($tokenIds);
        if ($dOut->rows !== $T || $dOut->cols !== $this->dModel) {
            throw new \InvalidArgumentException(
                "EmbeddingLayer backward shape mismatch: expected ($T, {$this->dModel}), got ({$dOut->rows}, {$dOut->cols})."
            );
        }
        // Get a reference (so changes are kept) to our gradient table.
        $grad = $this->weightGrad->data();
        // For every position in the sentence, add its incoming gradient
        // to the row of W that was used for that token.
        for ($t = 0; $t < $T; $t++) {
            $id = $tokenIds[$t];
            for ($d = 0; $d < $this->dModel; $d++) {
                $grad[$id * $this->dModel + $d] += $dOut->data()[$t * $this->dModel + $d];
            }
        }
        $this->weightGrad = new Tensor($this->vocabSize, $this->dModel, $grad);

        // Embedding has no "input gradient" in the traditional sense
        // (the input is integer ids, not real numbers, so it doesn't
        // have a derivative). Return a placeholder of the right shape.
        return Tensor::zeros($T, 1);
    }

    /**
     * Hand the computed weight gradient to the trainer. The trainer
     * uses it (with Adam) to update the embedding table.
     */
    public function getWeightGrad(): Tensor
    {
        if ($this->weightGrad === null) {
            throw new \RuntimeException('No weight gradient available; call forward first.');
        }

        return $this->weightGrad;
    }

    /**
     * Forget everything. Used between training runs so old data
     * doesn't leak into a new one.
     */
    public function reset(): void
    {
        $this->lastInput = null;
        $this->lastOutput = null;
        $this->weightGrad = null;
    }
}
