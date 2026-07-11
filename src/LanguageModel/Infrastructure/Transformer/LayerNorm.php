<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Transformer;

/**
 * Layer normalization (per-row, applied to the last dim of a 2D tensor).
 *
 * WHAT IS LAYER NORM?
 * -------------------
 * Neural networks can have numbers that get very big or very small as
 * they flow through many layers. That makes training unstable. Layer
 * norm fixes this by re-centering and re-scaling each row of data.
 *
 * THE RECIPE (per row):
 *   1. Compute the mean and variance of the row.
 *   2. Subtract the mean and divide by the standard deviation.
 *      Now the row has mean 0 and variance 1.
 *   3. Multiply each position by a learnable scale "gamma".
 *   4. Add a learnable shift "beta".
 *
 * The eps (1e-5) inside the sqrt stops us from dividing by 0 if all
 * the numbers in a row happen to be identical.
 *
 * Forward:  y = ((x - mean) / sqrt(var + eps)) * gamma + beta
 * Backward: standard analytical gradient; see comments below.
 *
 * gamma and beta are parameter vectors of length dModel, one per feature.
 */
final class LayerNorm
{
    // Tiny constant added inside the sqrt to keep the math safe.
    public const EPS = 1e-5;

    // Things from the last forward() call, kept for backward().
    private ?Tensor $lastInput = null;       // the original input
    private ?Tensor $lastNormalized = null;  // the row after mean/std normalization
    private ?Tensor $lastMean = null;        // one mean per row
    private ?Tensor $lastVar = null;         // one variance per row
    /** @var list<float>|null */
    private ?array $lastGamma = null;        // the gamma values used

    // Gradients for gamma and beta, filled in by backward().
    private ?Tensor $gammaGrad = null;
    private ?Tensor $betaGrad = null;

    public function __construct(public readonly int $dModel)
    {
    }

    /**
     * Build the two learnable parameters.
     * gamma starts at 1 (so the output is just the normalized value).
     * beta starts at 0 (so the output is centered).
     *
     * @return array{gamma: Tensor, beta: Tensor}
     */
    public function initParams(RandomGenerator $rng): array
    {
        // gamma = vector of ones, shape (dModel, 1)
        $gamma = new Tensor($this->dModel, 1, array_fill(0, $this->dModel, 1.0));
        // beta = vector of zeros, shape (dModel, 1)
        $beta = Tensor::zeros($this->dModel, 1);

        return ['gamma' => $gamma, 'beta' => $beta];
    }

    /**
     * The forward pass: normalize each row to mean ~0, std ~1,
     * then scale and shift.
     *
     * For every row we:
     *   1. Compute the average (mean) of its D numbers.
     *   2. Compute the average of the squared distances from the mean
     *      (that's the variance).
     *   3. Subtract the mean and divide by sqrt(variance + eps).
     *   4. Multiply by gamma and add beta.
     */
    public function forward(Tensor $input, Tensor $gamma, Tensor $beta): Tensor
    {
        $N = $input->rows;
        $D = $input->cols;
        if ($D !== $this->dModel) {
            throw new \InvalidArgumentException("LayerNorm dModel mismatch: expected {$this->dModel}, got $D.");
        }
        if ($gamma->rows !== $D || $beta->rows !== $D) {
            throw new \InvalidArgumentException('LayerNorm gamma/beta must be vectors of length dModel.');
        }

        $out = array_fill(0, $N * $D, 0.0);
        $means = [];
        $vars = [];
        $normed = array_fill(0, $N * $D, 0.0);
        for ($i = 0; $i < $N; $i++) {
            // Pull out one row of the input.
            $row = \array_slice($input->data(), $i * $D, $D);
            // Step 1: mean = sum / D.
            $mean = array_sum($row) / $D;
            // Step 2: variance = average of (x - mean)^2.
            $var = 0.0;
            foreach ($row as $v) {
                $d = $v - $mean;
                $var += $d * $d;
            }
            $var /= $D;
            $means[] = $mean;
            $vars[] = $var;
            // 1 / sqrt(var + eps) is the "inverse standard deviation".
            // We precompute it because we'll use it once per element.
            $invStd = 1.0 / \sqrt($var + self::EPS);
            for ($j = 0; $j < $D; $j++) {
                // Step 3: (x - mean) * invStd -> normalized value xhat.
                $xhat = ($row[$j] - $mean) * $invStd;
                $normed[$i * $D + $j] = $xhat;
                // Step 4: scale by gamma[j] and shift by beta[j].
                $out[$i * $D + $j] = $xhat * $gamma->data()[$j] + $beta->data()[$j];
            }
        }
        // Save everything for the backward pass.
        $this->lastInput = $input;
        $this->lastMean = new Tensor($N, 1, $means);
        $this->lastVar = new Tensor($N, 1, $vars);
        $this->lastNormalized = new Tensor($N, $D, $normed);
        $this->lastGamma = $gamma->data();
        $this->gammaGrad = Tensor::zeros($D, 1);
        $this->betaGrad = Tensor::zeros($D, 1);

        return new Tensor($N, $D, $out);
    }

    /**
     * The backward pass: figure out how gamma, beta, and the input
     * should change.
     *
     * We follow the derivation from the original "Layer Normalization"
     * paper (Ba et al., 2016, equation 11). The formula is a little
     * tricky because changing one input number also changes the
     * mean and variance of the whole row.
     */
    public function backward(Tensor $dOut): Tensor
    {
        if ($this->lastInput === null
            || $this->lastMean === null
            || $this->lastVar === null
            || $this->lastNormalized === null
            || $this->lastGamma === null
        ) {
            throw new \RuntimeException('LayerNorm::backward called before forward.');
        }
        $N = $dOut->rows;
        $D = $dOut->cols;
        if ($N !== $this->lastInput->rows || $D !== $this->lastInput->cols) {
            throw new \InvalidArgumentException('LayerNorm backward shape mismatch.');
        }

        // Allocate the three gradients we will compute.
        $dGamma = array_fill(0, $D, 0.0);
        $dBeta = array_fill(0, $D, 0.0);
        $dInput = array_fill(0, $N * $D, 0.0);
        // Local shortcuts to make the loops below less noisy.
        $gammaData = $this->lastGamma;
        $xhatData = $this->lastNormalized->data();
        $dOutData = $dOut->data();

        for ($i = 0; $i < $N; $i++) {
            $var = $this->lastVar->data()[$i];
            $invStd = 1.0 / \sqrt($var + self::EPS);
            // These two sums are needed for the dInput formula.
            // sum(dxHat)       = sum of incoming gradients
            // sum(dxHat * xHat) = sum weighted by the normalized values
            $sumDxHat = 0.0;
            $sumDxHatXhat = 0.0;
            for ($j = 0; $j < $D; $j++) {
                $xhat = $xhatData[$i * $D + $j];
                $do = $dOutData[$i * $D + $j];
                // dxHat is the gradient w.r.t. the normalized value.
                // It comes from y = xhat * gamma[j] + beta[j],
                // so dL/dxhat = dL/dy * gamma[j].
                $dxHat = $do * $gammaData[$j];
                $sumDxHat += $dxHat;
                $sumDxHatXhat += $dxHat * $xhat;
                // dGamma and dBeta: how each parameter should change.
                // y = xhat * gamma[j] + beta[j] -> dGamma += dOut * xhat,
                //                                    dBeta += dOut.
                $dGamma[$j] += $do * $xhat;
                $dBeta[$j] += $do;
            }
            for ($j = 0; $j < $D; $j++) {
                $xhat = $xhatData[$i * $D + $j];
                $dxHat = $dOutData[$i * $D + $j] * $gammaData[$j];
                // Standard LayerNorm backward (Ba et al. 2016, Eq. 11):
                //   dx_i = (1/D) * invStd * (D * dxHat_i - sum(dxHat) - xHat_i * sum(dxHat * xHat))
                // In plain English: each input's gradient is its own
                // contribution, minus a small "average effect" (because
                // the mean depends on all inputs), minus a small
                // "variance effect" (because the std depends on all
                // inputs).
                $dInput[$i * $D + $j] = $invStd * (
                    $dxHat
                    - $sumDxHat / $D
                    - $xhat * $sumDxHatXhat / $D
                );
            }
        }

        // Save the parameter gradients for the trainer.
        $this->gammaGrad = new Tensor($D, 1, $dGamma);
        $this->betaGrad = new Tensor($D, 1, $dBeta);

        return new Tensor($N, $D, $dInput);
    }

    public function getGammaGrad(): Tensor
    {
        if ($this->gammaGrad === null) {
            throw new \RuntimeException('No gamma gradient available; call forward first.');
        }

        return $this->gammaGrad;
    }

    public function getBetaGrad(): Tensor
    {
        if ($this->betaGrad === null) {
            throw new \RuntimeException('No beta gradient available; call forward first.');
        }

        return $this->betaGrad;
    }

    public function reset(): void
    {
        $this->lastInput = null;
        $this->lastNormalized = null;
        $this->lastMean = null;
        $this->lastVar = null;
        $this->lastGamma = null;
        $this->gammaGrad = null;
        $this->betaGrad = null;
    }
}
