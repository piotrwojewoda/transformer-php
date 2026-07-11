<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Transformer;

/**
 * Softmax + cross-entropy loss combined.
 *
 * WHAT IS THIS?
 * --------------
 * At the end of the Transformer, every position has a list of "logits":
 * one number for every possible next character. We need two things:
 *   1. A single number that says "how wrong was the model?" (the loss)
 *   2. A gradient that says "which way should each logit move?"
 *
 * SOFTMAX turns logits into probabilities (all positive, sum to 1).
 * CROSS-ENTROPY then says: how surprised are we by the right answer?
 * If we predicted p=0.99 for the right character, the loss is small
 * (-log(0.99) ~ 0.01). If we predicted p=0.01, the loss is huge.
 *
 * Forward returns the scalar mean loss over a (T, V) logits matrix
 * and the targets. Backward returns the gradient w.r.t. the logits,
 * which is the standard stable form (softmax - onehot) / T.
 */
final class SoftmaxCrossEntropy
{
    // The probabilities we computed in the last forward call. We
    // need them in backward to build the gradient.
    private ?Tensor $lastProbs = null;

    /**
     * Compute the average loss.
     *
     * @param Tensor $logits (T, V) raw scores for V possible characters
     *                        at each of the T positions.
     * @param list<int> $targets the correct token id for each position
     * @return float the mean loss (one number for the whole batch)
     */
    public function forward(Tensor $logits, array $targets): float
    {
        $T = $logits->rows; // T = number of positions
        $V = $logits->cols; // V = vocabulary size (how many tokens to choose from)
        if (\count($targets) !== $T) {
            throw new \InvalidArgumentException("SoftmaxCrossEntropy: target count mismatch.");
        }
        $probs = array_fill(0, $T * $V, 0.0);
        $loss = 0.0;
        for ($t = 0; $t < $T; $t++) {
            // Pull out the logits for this position.
            $row = \array_slice($logits->data(), $t * $V, $V);
            // The "subtract max" trick keeps exp() from overflowing.
            $max = max($row);
            $sum = 0.0;
            $exps = [];
            foreach ($row as $z) {
                $e = \exp($z - $max);
                $exps[] = $e;
                $sum += $e;
            }
            // Convert each logit to a probability.
            for ($j = 0; $j < $V; $j++) {
                $probs[$t * $V + $j] = $exps[$j] / $sum;
            }
            // The cross-entropy loss for this position: -log(p_of_correct).
            // The max(..., 1e-12) guards against log(0) = -infinity.
            $y = $targets[$t];
            $p = $probs[$t * $V + $y];
            $loss += -\log(max($p, 1e-12));
        }
        // Average the per-position losses so the loss doesn't grow
        // just because we used a longer sequence.
        $loss /= max(1, $T);
        // Save the probabilities for the backward pass.
        $this->lastProbs = new Tensor($T, $V, $probs);

        return $loss;
    }

    /**
     * The backward pass: compute the gradient of the loss with
     * respect to the logits.
     *
     * BEAUTIFUL FACT:
     * When you combine softmax with cross-entropy, the gradient has
     * a very clean form:
     *   d_logits = (softmax_probs - one_hot_target) / T
     * i.e. for every position: subtract 1 from the probability of the
     * correct answer, then divide by the number of positions.
     *
     * @param list<int> $targets the same correct token ids used in forward
     */
    public function backward(array $targets): Tensor
    {
        if ($this->lastProbs === null) {
            throw new \RuntimeException('SoftmaxCrossEntropy::backward called before forward.');
        }
        $T = $this->lastProbs->rows;
        $V = $this->lastProbs->cols;
        // Start from a copy of the probabilities.
        $grad = $this->lastProbs->data();
        // Subtract 1 from the probability of the correct token. This
        // is the "one-hot" trick: the correct class is "1, all others
        // are 0", so prob - onehot = error.
        for ($t = 0; $t < $T; $t++) {
            $grad[$t * $V + $targets[$t]] -= 1.0;
        }
        // Scale by 1/T because we took the mean in forward.
        $scale = 1.0 / max(1, $T);
        foreach ($grad as $i => $g) {
            $grad[$i] = $g * $scale;
        }

        return new Tensor($T, $V, $grad);
    }

    public function reset(): void
    {
        $this->lastProbs = null;
    }
}
