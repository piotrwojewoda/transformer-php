<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Transformer;

/**
 * Position-wise feed-forward network: ReLU(x @ W1 + b1) @ W2 + b2.
 *
 * WHAT IS THIS?
 * --------------
 * After attention, every token's vector is mixed up with the other
 * tokens' information. The feed-forward layer (FFN) then processes
 * each token's vector on its own, with a small two-layer neural net:
 *
 *   hidden = ReLU(input @ W1 + b1)
 *   output = hidden @ W2 + b2
 *
 * W1 widens the vector from dModel up to dFf (the "hidden" size),
 * W2 shrinks it back down. The ReLU in the middle throws away
 * anything negative (it's like a one-way filter).
 *
 * SHAPES:
 *   W1: (dModel, dFf), b1: (dFf,)
 *   W2: (dFf, dModel), b2: (dModel,)
 *
 * Backward is the standard chain rule through ReLU and two matmuls.
 */
final class FeedForwardLayer
{
    // Things we need to remember from forward() to use in backward().
    private ?Tensor $lastInput = null;
    private ?Tensor $lastH1 = null;   // the hidden activations after ReLU
    private ?Tensor $lastReluMask = null; // 1 where z1 > 0, else 0

    // Gradients for the four parameters, filled in by backward().
    private ?Tensor $w1Grad = null;
    private ?Tensor $b1Grad = null;
    private ?Tensor $w2Grad = null;
    private ?Tensor $b2Grad = null;

    // We don't store W1 and W2 inside forward() because the trainer
    // holds the "real" copies. The trainer hands them to us via
    // setLastWeights() right before calling backward().
    private ?Tensor $lastW1 = null;
    private ?Tensor $lastW2 = null;

    public function __construct(
        public readonly int $dModel, // width of the input/output vectors
        public readonly int $dFf,    // width of the hidden layer
    ) {
    }

    /**
     * Build four starting parameters: W1, b1, W2, b2.
     * The weights start as small random numbers (He initialization),
     * the biases start as zeros.
     *
     * WHY HE INIT?
     * ReLU kills half the numbers (the negatives). To compensate, we
     * use std = sqrt(2 / fanIn), which makes the signal a healthy
     * size at the start of training.
     *
     * @return array{w1: Tensor, b1: Tensor, w2: Tensor, b2: Tensor}
     */
    public function initParams(RandomGenerator $rng): array
    {
        return [
            'w1' => $this->initMatrix($rng, $this->dModel, $this->dFf),
            'b1' => Tensor::zeros($this->dFf, 1),
            'w2' => $this->initMatrix($rng, $this->dFf, $this->dModel),
            'b2' => Tensor::zeros($this->dModel, 1),
        ];
    }

    /**
     * Helper: build a random matrix with He initialization.
     * std = sqrt(2 / fanIn) is a good starting range for layers
     * followed by a ReLU.
     */
    private function initMatrix(RandomGenerator $rng, int $rows, int $cols): Tensor
    {
        // He initialisation (good for ReLU): std = sqrt(2/fanIn).
        $std = \sqrt(2.0 / $rows);
        $data = [];
        for ($i = 0; $i < $rows * $cols; $i++) {
            // Box-Muller, like in the other layers.
            $u1 = max(1e-12, $rng->nextFloat());
            $u2 = $rng->nextFloat();
            $z = \sqrt(-2.0 * \log($u1)) * \cos(2.0 * M_PI * $u2);
            $data[] = $z * $std;
        }

        return new Tensor($rows, $cols, $data);
    }

    /**
     * The forward pass: input (T, dModel) -> output (T, dModel).
     *
     * STEPS:
     * 1. z1 = input @ W1          (T, dFf)
     * 2. z1 = z1 + b1             (T, dFf) (broadcast the bias vector)
     * 3. h1 = ReLU(z1)            (T, dFf) (zero out negatives)
     * 4. y  = h1 @ W2             (T, dModel)
     * 5. y  = y + b2              (T, dModel)
     *
     * We also remember a "relu mask": a copy of z1 with 1.0 where
     * z1 was positive and 0.0 where it was negative. We need it
     * later for the backward pass.
     *
     * @param array{w1: Tensor, b1: Tensor, w2: Tensor, b2: Tensor} $params
     */
    public function forward(Tensor $input, array $params): Tensor
    {
        $T = $input->rows; // T = number of tokens
        $D = $input->cols; // D = dModel
        $F = $this->dFf;   // F = hidden size
        if ($D !== $this->dModel) {
            throw new \InvalidArgumentException("FFN dModel mismatch: expected {$this->dModel}, got $D.");
        }
        // z1 = input @ W1  -> shape (T, F)
        $z1 = $input->matmul($params['w1']);
        // Add bias b1 to every row (the bias is the same for every token).
        $b1 = $params['b1']->data();
        for ($t = 0; $t < $T; $t++) {
            for ($j = 0; $j < $F; $j++) {
                $z1->data()[$t * $F + $j] += $b1[$j];
            }
        }
        // ReLU: keep positive values, turn negatives into 0.
        $h1Data = $z1->apply(static fn (float $v) => $v > 0.0 ? $v : 0.0)->data();
        // ReLU mask: 1 where z1 was positive, 0 otherwise.
        // We need this for the backward pass.
        $reluMask = array_map(static fn (float $v) => $v > 0.0 ? 1.0 : 0.0, $z1->data());
        // y = h1 @ W2 -> shape (T, dModel)
        $h1 = new Tensor($T, $F, $h1Data);
        $y = $h1->matmul($params['w2']);
        // Add bias b2 to every row.
        $b2 = $params['b2']->data();
        for ($t = 0; $t < $T; $t++) {
            for ($j = 0; $j < $D; $j++) {
                $y->data()[$t * $D + $j] += $b2[$j];
            }
        }

        // Save what we need for the backward pass.
        $this->lastInput = $input;
        $this->lastH1 = $h1;
        $this->lastReluMask = new Tensor($T, $F, $reluMask);
        // Pre-allocate empty gradient matrices.
        $this->w1Grad = Tensor::zeros($params['w1']->rows, $params['w1']->cols);
        $this->b1Grad = Tensor::zeros($F, 1);
        $this->w2Grad = Tensor::zeros($params['w2']->rows, $params['w2']->cols);
        $this->b2Grad = Tensor::zeros($D, 1);

        return $y;
    }

    /**
     * The backward pass: figure out how each weight and the input
     * should change.
     *
     * We walk backwards through the forward steps:
     *   y = h1 @ W2 + b2 -> undo bias add, undo matmul, undo ReLU
     *   h1 = ReLU(z1)     -> multiply by the ReLU mask
     *   z1 = input @ W1 + b1 -> undo bias add, undo matmul
     */
    public function backward(Tensor $dOut): Tensor
    {
        if ($this->lastInput === null || $this->lastH1 === null || $this->lastReluMask === null) {
            throw new \RuntimeException('FFN::backward called before forward.');
        }
        $T = $dOut->rows;
        $D = $dOut->cols;
        $F = $this->dFf;

        // We need the actual W1 and W2 to compute gradients. The
        // trainer hands them to us right before calling backward().
        if ($this->lastW2 === null || $this->lastW1 === null) {
            throw new \RuntimeException('FFN::backward called without cached weights; call setLastWeights() first.');
        }

        // Step 1: undo the bias b2 in y = h1 @ W2 + b2.
        //   If b2 added the same value to every token, then during
        //   backward the gradient of b2 is just the sum across all
        //   tokens of the incoming gradient dY.
        $dB2 = array_fill(0, $D, 0.0);
        for ($t = 0; $t < $T; $t++) {
            for ($j = 0; $j < $D; $j++) {
                $dB2[$j] += $dOut->data()[$t * $D + $j];
            }
        }
        // Step 2: undo y = h1 @ W2.
        //   dW2 = h1.T @ dY  (how each weight should change)
        //   dH1 = dY @ W2.T  (gradient to pass back to the ReLU)
        $dW2 = $this->lastH1->transpose()->matmul($dOut)->data();
        $dH1 = $dOut->matmul($this->lastW2->transpose());
        // Step 3: undo ReLU.
        //   ReLU is identity for x > 0, and 0 for x <= 0. Its
        //   derivative is 1 for x > 0, and 0 otherwise. We saved
        //   the mask (1s and 0s) in forward(). Multiplying by the
        //   mask zeros out the gradient for the dead neurons.
        $dZ1 = $dH1->mul($this->lastReluMask);
        // Step 4: undo the bias b1 in z1 = input @ W1 + b1.
        //   Same idea as for b2: sum the gradient across tokens.
        $dB1 = array_fill(0, $F, 0.0);
        for ($t = 0; $t < $T; $t++) {
            for ($j = 0; $j < $F; $j++) {
                $dB1[$j] += $dZ1->data()[$t * $F + $j];
            }
        }
        // Step 5: undo z1 = input @ W1.
        //   dW1 = input.T @ dZ1
        //   dInput = dZ1 @ W1.T
        $dW1 = $this->lastInput->transpose()->matmul($dZ1)->data();
        $dInput = $dZ1->matmul($this->lastW1->transpose());

        // Save the four weight gradients for the trainer to pick up.
        $this->w2Grad = new Tensor($this->lastW2->rows, $this->lastW2->cols, $dW2);
        $this->b2Grad = new Tensor($D, 1, $dB2);
        $this->w1Grad = new Tensor($this->lastW1->rows, $this->lastW1->cols, $dW1);
        $this->b1Grad = new Tensor($F, 1, $dB1);

        return $dInput;
    }

    /**
     * Must be called by the trainer with the same W1, W2 used in
     * forward. We don't store the weights inside forward() because
     * the trainer owns them and uses the same instances for the
     * Adam optimizer.
     */
    public function setLastWeights(Tensor $w1, Tensor $w2): void
    {
        $this->lastW1 = $w1;
        $this->lastW2 = $w2;
    }

    public function getW1Grad(): Tensor
    {
        if ($this->w1Grad === null) {
            throw new \RuntimeException('No w1 gradient; call forward first.');
        }

        return $this->w1Grad;
    }

    public function getB1Grad(): Tensor
    {
        if ($this->b1Grad === null) {
            throw new \RuntimeException('No b1 gradient; call forward first.');
        }

        return $this->b1Grad;
    }

    public function getW2Grad(): Tensor
    {
        if ($this->w2Grad === null) {
            throw new \RuntimeException('No w2 gradient; call forward first.');
        }

        return $this->w2Grad;
    }

    public function getB2Grad(): Tensor
    {
        if ($this->b2Grad === null) {
            throw new \RuntimeException('No b2 gradient; call forward first.');
        }

        return $this->b2Grad;
    }

    /**
     * Forget everything. Used between training runs.
     */
    public function reset(): void
    {
        $this->lastInput = null;
        $this->lastH1 = null;
        $this->lastReluMask = null;
        $this->w1Grad = null;
        $this->b1Grad = null;
        $this->w2Grad = null;
        $this->b2Grad = null;
        $this->lastW1 = null;
        $this->lastW2 = null;
    }
}
