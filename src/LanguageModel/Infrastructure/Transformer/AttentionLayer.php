<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Transformer;

/**
 * Single-head causal self-attention.
 *
 * WHAT IS "ATTENTION"?
 * ---------------------
 * Imagine you're reading the sentence:
 *     "The cat sat on the mat because it was tired."
 *
 * When you try to understand the word "it", your brain looks back at
 * the earlier words to figure out what "it" refers to. Attention
 * is the same idea: for every word, the model decides which earlier
 * words are important and pays more attention to them.
 *
 * THREE PIECES:
 * -------------
 * - Query  (Q) = "what am I looking for?"
 * - Key    (K) = "what do I contain?"
 * - Value  (V) = "what information do I pass on if I'm chosen?"
 *
 * For each word, we score it against every earlier word by computing
 * a dot product between its Q and the other word's K. We turn those
 * scores into probabilities (softmax), then take a weighted sum of
 * the V vectors.
 *
 * "CAUSAL" MEANS: a word can only look at itself and EARLIER words,
 * not future words. We don't want the model to cheat by peeking at
 * the answer.
 *
 * THE FORMULAS (forward, per token t):
 *   Q = X @ Wq, K = X @ Wk, V = X @ Wv           (T x dModel)
 *   scores = Q @ K.T / sqrt(dModel)               (T x T)
 *   mask   = upper-triangular -inf (causal)        (T x T)
 *   A      = softmax(scores + mask)                (T x T)
 *   Y      = A @ V                                 (T x dModel)
 *   O      = Y @ Wo                                (T x dModel)
 *
 * Backward returns dX (gradient w.r.t. input) and stores
 * dWq, dWk, dWv, dWo for the trainer.
 */
final class AttentionLayer
{
    // Everything from the last forward() call, kept so backward() can
    // use them. The names match the math: e.g. $lastQ is the Q matrix
    // we computed last time, $lastA is the attention probabilities.
    private ?Tensor $lastInput = null;
    private ?Tensor $lastQ = null;
    private ?Tensor $lastK = null;
    private ?Tensor $lastV = null;
    private ?Tensor $lastScores = null;
    private ?Tensor $lastA = null;
    private ?Tensor $lastY = null;

    // Gradients for the four weight matrices, filled in by backward().
    private ?Tensor $wQGrad = null;
    private ?Tensor $wKGrad = null;
    private ?Tensor $wVGrad = null;
    private ?Tensor $wOGrad = null;

    // The actual weights from the last forward, also needed for
    // backward (we use them transposed).
    private ?Tensor $lastWq = null;
    private ?Tensor $lastWk = null;
    private ?Tensor $lastWv = null;
    private ?Tensor $lastWo = null;

    public function __construct(public readonly int $dModel)
    {
    }

    /**
     * Make four random square matrices, one for each of Wq, Wk, Wv, Wo.
     * Each matrix is dModel x dModel, so it can mix the dModel numbers
     * of the input any way it wants.
     *
     * We use small random numbers (std = 1/sqrt(dModel)) so the model
     * starts with sensible values and doesn't blow up.
     *
     * @return array{wq: Tensor, wk: Tensor, wv: Tensor, wo: Tensor}
     */
    public function initParams(RandomGenerator $rng): array
    {
        $std = 1.0 / \sqrt($this->dModel);
        // Helper: build one random square matrix.
        $init = function () use ($rng, $std): Tensor {
            $data = [];
            for ($i = 0; $i < $this->dModel * $this->dModel; $i++) {
                // Box-Muller, like in the embedding layer.
                $u1 = max(1e-12, $rng->nextFloat());
                $u2 = $rng->nextFloat();
                $z = \sqrt(-2.0 * \log($u1)) * \cos(2.0 * M_PI * $u2);
                $data[] = $z * $std;
            }

            return new Tensor($this->dModel, $this->dModel, $data);
        };

        return [
            'wq' => $init(),
            'wk' => $init(),
            'wv' => $init(),
            'wo' => $init(),
        ];
    }

    /**
     * The forward pass: turn (T, dModel) input into (T, dModel) output.
     *
     * STEPS:
     * 1. Project the input three different ways to get Q, K, V.
     * 2. Score every (i, j) pair: how much should token i look at j?
     *    score = (Q_i . K_j) / sqrt(dModel). The /sqrt keeps numbers
     *    from getting too big.
     * 3. Apply the causal mask: j > i means "future", so we set the
     *    score to -infinity (so after softmax it becomes 0).
     * 4. Softmax each row of the score matrix -> attention weights A.
     *    Each row is now a probability distribution that sums to 1.
     * 5. Mix the V vectors using A as weights: Y = A @ V.
     * 6. One last linear projection with Wo: O = Y @ Wo.
     *
     * @param array{wq: Tensor, wk: Tensor, wv: Tensor, wo: Tensor} $params
     */
    public function forward(Tensor $input, array $params): Tensor
    {
        $T = $input->rows; // T = how many tokens in the sentence
        $D = $input->cols; // D = dModel, the width of every vector
        if ($D !== $this->dModel) {
            throw new \InvalidArgumentException("Attention dModel mismatch: expected {$this->dModel}, got $D.");
        }
        // 1. Three different "views" of the input.
        $Q = $input->matmul($params['wq']);
        $K = $input->matmul($params['wk']);
        $V = $input->matmul($params['wv']);

        // 2. Score matrix: how related is each pair of tokens?
        //    Q is (T, D), K.T is (D, T) -> result is (T, T).
        $scale = 1.0 / \sqrt((float) $D);
        $scores = $Q->matmul($K->transpose())->scale($scale);
        // 3. Causal mask: set future positions to -infinity.
        //    After softmax, e^(-inf) = 0, so the model ignores them.
        for ($i = 0; $i < $T; $i++) {
            for ($j = $i + 1; $j < $T; $j++) {
                $scores->data()[$i * $T + $j] = -INF;
            }
        }
        // 4. Softmax each row -> attention probabilities A.
        $A = $this->rowSoftmax($scores, $T);
        // 5. Mix values: each row of A picks which V rows matter.
        $Y = $A->matmul($V);
        // 6. Final projection: one more linear mix.
        $O = $Y->matmul($params['wo']);

        // Stash everything for the backward pass.
        $this->lastInput = $input;
        $this->lastQ = $Q;
        $this->lastK = $K;
        $this->lastV = $V;
        $this->lastScores = $scores;
        $this->lastA = $A;
        $this->lastY = $Y;
        $this->lastWq = $params['wq'];
        $this->lastWk = $params['wk'];
        $this->lastWv = $params['wv'];
        $this->lastWo = $params['wo'];
        // Pre-allocate empty gradients; backward() will fill them.
        $this->wQGrad = Tensor::zeros($D, $D);
        $this->wKGrad = Tensor::zeros($D, $D);
        $this->wVGrad = Tensor::zeros($D, $D);
        $this->wOGrad = Tensor::zeros($D, $D);

        return $O;
    }

    /**
     * Apply softmax to each row of the score matrix.
     *
     * WHAT IS SOFTMAX?
     * Turn a list of numbers into probabilities that sum to 1.
     * Formula: p_i = exp(x_i - max) / sum(exp(x_j - max))
     * The "subtract max" trick keeps the math from overflowing when
     * numbers get big.
     *
     * WHY PER ROW?
     * Each row of the score matrix is "how should token i split its
     * attention across the other tokens?". Softmax makes that split
     * a valid probability distribution.
     */
    private function rowSoftmax(Tensor $scores, int $T): Tensor
    {
        $probs = array_fill(0, $T * $T, 0.0);
        for ($i = 0; $i < $T; $i++) {
            // Pull out one row.
            $row = \array_slice($scores->data(), $i * $T, $T);
            // Find the biggest value in this row (for the stability trick).
            $max = -INF;
            foreach ($row as $v) {
                if ($v > $max) {
                    $max = $v;
                }
            }
            $sum = 0.0;
            $exps = [];
            foreach ($row as $v) {
                // -inf means "ignore this position". exp(-inf) = 0.
                if ($v === -INF) {
                    $exps[] = 0.0;
                    continue;
                }
                // Subtract the max first to keep the numbers small.
                $e = \exp($v - $max);
                $exps[] = $e;
                $sum += $e;
            }
            // Divide each exponential by the total to get a probability.
            for ($j = 0; $j < $T; $j++) {
                $probs[$i * $T + $j] = $sum > 0 ? $exps[$j] / $sum : 0.0;
            }
        }

        return new Tensor($T, $T, $probs);
    }

    /**
     * The backward pass: compute how the loss changes when each
     * weight or input changes a little bit.
     *
     * THINK OF IT AS:
     * "If I wiggle Wq a tiny bit, how does the output wiggle?"
     * "If I wiggle the input, how does the output wiggle?"
     *
     * We work backwards through the same chain of operations we did
     * in forward, undoing each step in reverse.
     */
    public function backward(Tensor $dOut): Tensor
    {
        // All these "last*" things must be set by forward() first.
        if ($this->lastInput === null
            || $this->lastQ === null
            || $this->lastK === null
            || $this->lastV === null
            || $this->lastScores === null
            || $this->lastA === null
            || $this->lastY === null
            || $this->lastWq === null
            || $this->lastWk === null
            || $this->lastWv === null
            || $this->lastWo === null
        ) {
            throw new \RuntimeException('Attention::backward called before forward.');
        }
        $T = $dOut->rows;
        $D = $dOut->cols;
        // Aliases to make the math below easier to read.
        $X = $this->lastInput;
        $A = $this->lastA;
        $V = $this->lastV;
        $Q = $this->lastQ;
        $K = $this->lastK;
        $scale = 1.0 / \sqrt((float) $D);

        // Step 1: undo the final projection O = Y @ Wo.
        //   Forward: O = Y @ Wo, so:
        //     dWo = Y.T @ dOut   (shape D x D)
        //     dY  = dOut @ Wo.T  (shape T x D)
        $dWo = $this->lastY->transpose()->matmul($dOut);
        $dY = $dOut->matmul($this->lastWo->transpose());

        // Step 2: undo Y = A @ V.
        //   Forward: Y = A @ V, so:
        //     dA = dY @ V.T   (how attention weights should change)
        //     dV = A.T @ dY   (how values should change)
        $dA = $dY->matmul($V->transpose());
        $dV = $A->transpose()->matmul($dY);

        // Step 3: undo softmax.
        //   Softmax gradient has a clean formula. If a softmax row
        //   is a = [a1, a2, ...] and the loss gradient for that row
        //   is g = [g1, g2, ...], then the gradient w.r.t. the
        //   pre-softmax row is:
        //       d_i = a_i * (g_i - sum(a_j * g_j))
        //   This is the Jacobian of softmax applied to a vector.
        $dScores = new Tensor($T, $T, array_fill(0, $T * $T, 0.0));
        for ($i = 0; $i < $T; $i++) {
            $aRow = \array_slice($A->data(), $i * $T, $T);
            $gRow = \array_slice($dA->data(), $i * $T, $T);
            // Precompute the dot product of the row with its gradient.
            $dot = 0.0;
            for ($j = 0; $j < $T; $j++) {
                $dot += $aRow[$j] * $gRow[$j];
            }
            // Apply the softmax derivative formula.
            for ($j = 0; $j < $T; $j++) {
                $dScores->data()[$i * $T + $j] = $aRow[$j] * ($gRow[$j] - $dot);
            }
        }
        // Don't forget the /sqrt(dModel) we did in forward!
        $dScores = $dScores->scale($scale);

        // Step 4: undo scores = Q @ K.T.
        //   Forward: scores = Q @ K.T, so:
        //     dQ = dScores @ K
        //     dK = dScores.T @ Q
        $dQ = $dScores->matmul($K);
        $dK = $dScores->transpose()->matmul($Q);

        // Step 5: undo the three projections Q = X @ Wq, K = X @ Wk, V = X @ Wv.
        //   For each: dW = X.T @ dThing, where "Thing" is Q, K, or V.
        $dWq = $X->transpose()->matmul($dQ);
        $dWk = $X->transpose()->matmul($dK);
        $dWv = $X->transpose()->matmul($dV);

        // Step 6: the gradient flowing back into the input.
        //   Each of Q, K, V was a different view of X, so we sum the
        //   three contributions:
        //     dX = dQ @ Wq.T + dK @ Wk.T + dV @ Wv.T
        $dX = $dQ->matmul($this->lastWq->transpose())
            ->add($dK->matmul($this->lastWk->transpose()))
            ->add($dV->matmul($this->lastWv->transpose()));

        // Save the weight gradients for the trainer to pick up.
        $this->wQGrad = $dWq;
        $this->wKGrad = $dWk;
        $this->wVGrad = $dWv;
        $this->wOGrad = $dWo;

        return $dX;
    }

    public function getWqGrad(): Tensor
    {
        if ($this->wQGrad === null) {
            throw new \RuntimeException('No wq grad; call forward first.');
        }

        return $this->wQGrad;
    }

    public function getWkGrad(): Tensor
    {
        if ($this->wKGrad === null) {
            throw new \RuntimeException('No wk grad; call forward first.');
        }

        return $this->wKGrad;
    }

    public function getWvGrad(): Tensor
    {
        if ($this->wVGrad === null) {
            throw new \RuntimeException('No wv grad; call forward first.');
        }

        return $this->wVGrad;
    }

    public function getWoGrad(): Tensor
    {
        if ($this->wOGrad === null) {
            throw new \RuntimeException('No wo grad; call forward first.');
        }

        return $this->wOGrad;
    }

    public function reset(): void
    {
        $this->lastInput = null;
        $this->lastQ = null;
        $this->lastK = null;
        $this->lastV = null;
        $this->lastScores = null;
        $this->lastA = null;
        $this->lastY = null;
        $this->lastWq = null;
        $this->lastWk = null;
        $this->lastWv = null;
        $this->lastWo = null;
        $this->wQGrad = null;
        $this->wKGrad = null;
        $this->wVGrad = null;
        $this->wOGrad = null;
    }
}
