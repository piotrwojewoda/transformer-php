<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Transformer;

/**
 * Adam optimizer (without bias correction).
 *
 * WHAT IS AN OPTIMIZER?
 * ---------------------
 * After backward() tells us how each weight should change, we need
 * to actually change it. That's the optimizer's job. The simplest
 * choice is "gradient descent": weight -= learning_rate * gradient.
 * But that often gets stuck or zigzags.
 *
 * ADAM = "Adaptive Moment Estimation". It keeps two running averages
 * of the gradient for every weight:
 *   m = running average of the gradient itself (the "first moment")
 *   v = running average of the gradient squared (the "second moment")
 * Then it updates the weight using a smartly-scaled version of m.
 *
 * The intuition: if a gradient has been big and consistent (large v),
 * we should take small steps (the optimizer is nervous about it).
 * If a gradient is small or noisy (small v), we can take bigger steps.
 *
 * For each parameter p with gradient g:
 *   m = beta1 * m + (1 - beta1) * g
 *   v = beta2 * v + (1 - beta2) * g^2
 *   p = p - lr * m / (sqrt(v) + eps)
 */
final class Adam
{
    // How much of the old m to keep. 0.9 means: the running average
    // is roughly the last 10 gradients.
    public const BETA1 = 0.9;
    // Same idea for v, but with a much longer memory (last ~1000
    // gradients) because v is squared and so is more stable.
    public const BETA2 = 0.999;
    // Tiny constant so we never divide by 0 in p = ... / sqrt(v).
    public const EPS = 1e-8;

    // Per-parameter Adam state. Keyed by a string path (like
    // "attn.0.wq:3:7") so we can find the right m, v for every
    // single number in every matrix.
    /** @var array<string, array{m: float, v: float}> */
    private array $state = [];

    public function __construct(
        public readonly float $learningRate, // how big each step can be
    ) {
        if ($learningRate <= 0) {
            throw new \InvalidArgumentException('Adam learning rate must be > 0.');
        }
    }

    /**
     * Do one Adam step on the whole weight dictionary.
     *
     * The weights are nested arrays (tokenEmbed, posEmbed, attn[layer],
     * etc.). We walk the tree once and update every leaf number.
     *
     * @param array<string, array<int, array<int, float>>> $grads
     *   flat dict of parameter paths -> 2D float matrix of gradients
     * @return array<string, array<int, array<int, float>>> updated weights
     */
    public function step(array $weights, array $grads): array
    {
        $newWeights = [];
        foreach ($weights as $path => $matrix) {
            $newWeights[$path] = $this->updateMatrix($matrix, $grads[$path] ?? [], $path);
        }

        return $newWeights;
    }

    /**
     * Walk through one matrix (a list of rows) and update each value.
     * PHP arrays can be many shapes, so we figure out which kind of
     * "row" we're dealing with and dispatch.
     */
    private function updateMatrix(array $matrix, array $grad, string $path): array
    {
        $out = [];
        foreach ($matrix as $i => $row) {
            $g = $grad[$i] ?? 0.0;
            // If the row is a dictionary (not a numbered list), recurse.
            if (\is_array($row) && !\array_is_list($row)) {
                $out[$i] = $this->updateDict($row, $g, "$path.$i");
                continue;
            }
            // If the row is a list of floats, it's a 1D vector or row.
            if (\is_array($row)) {
                $out[$i] = $this->update2D($row, $g, "$path:$i");
                continue;
            }
            // Otherwise it's a plain number.
            $out[$i] = $this->update1D($row, $g, "$path:$i");
        }

        return $out;
    }

    /**
     * Handle a dictionary-shaped row (a nested map).
     */
    private function updateDict(array $row, mixed $g, string $path): array
    {
        $out = [];
        foreach ($row as $j => $sub) {
            $out[$j] = $this->updateMatrix($sub, \is_array($g) ? ($g[$j] ?? []) : [], "$path.$j");
        }

        return $out;
    }

    /**
     * Update a list of floats. Each one is paired with its gradient
     * and updated by the Adam rule.
     */
    private function update2D(array $row, mixed $g, string $baseKey): array
    {
        $out = [];
        foreach ($row as $j => $p) {
            $gj = \is_array($g) ? ($g[$j] ?? 0.0) : (float) $g;
            if (\is_array($gj)) {
                $gj = 0.0;
            }
            $key = "$baseKey:$j";
            [$m, $v] = $this->momentum($key, (float) $gj);
            $out[$j] = (float) $p - $this->learningRate * $m / (\sqrt($v) + self::EPS);
        }

        return $out;
    }

    /**
     * Update a single number (a scalar parameter like a bias).
     */
    private function update1D(float|int $row, mixed $g, string $key): float
    {
        $gj = \is_array($g) ? 0.0 : (float) $g;
        [$m, $v] = $this->momentum($key, $gj);

        return (float) $row - $this->learningRate * $m / (\sqrt($v) + self::EPS);
    }

    /**
     * The two running averages: m and v.
     *
     * m = beta1 * m + (1 - beta1) * g
     *   This is an "exponential moving average". With beta1 = 0.9,
     *   m is roughly the average of the last 10 gradients.
     *
     * v = beta2 * v + (1 - beta2) * g^2
     *   Same idea but for the SQUARED gradient. With beta2 = 0.999,
     *   v is the average of the last ~1000 squared gradients.
     *   v is always non-negative.
     */
    private function momentum(string $key, float $g): array
    {
        $m = $this->state[$key]['m'] ?? 0.0;
        $v = $this->state[$key]['v'] ?? 0.0;
        $m = self::BETA1 * $m + (1.0 - self::BETA1) * $g;
        $v = self::BETA2 * $v + (1.0 - self::BETA2) * ($g * $g);
        $this->state[$key] = ['m' => $m, 'v' => $v];

        return [$m, $v];
    }

    /**
     * Read the whole Adam state. Used to save it to the database so
     * training can resume after a worker restart.
     *
     * @return array<string, array{m: float, v: float}>
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * Replace the Adam state. Used when resuming training.
     *
     * @param array<string, array{m: float, v: float}> $state
     */
    public function setState(array $state): void
    {
        $this->state = $state;
    }

    public function clearState(): void
    {
        $this->state = [];
    }
}
