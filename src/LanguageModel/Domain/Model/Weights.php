<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Model;

/**
 * Immutable container for all trainable parameters of a LanguageModel.
 *
 * Structure (all matrices are row-major float arrays):
 *   - 'tokenEmbed'      : list<list<float>>       (vocabSize x dModel)
 *   - 'posEmbed'        : list<list<float>>       (maxSeqLen x dModel)
 *   - 'attn'            : list<layer>
 *       per layer: 'wq','wk','wv','wo'  (dModel x dModel)
 *   - 'ffn'             : list<layer>
 *       per layer: 'w1' (dModel x dFf), 'b1' (dFf),
 *                   'w2' (dFf x dModel), 'b2' (dModel)
 *   - 'final'           : list<list<float>>       (vocabSize x dModel)
 *       (transposed: vocab rows of dModel, so logits = h @ final.T,
 *        which is vocabSize x dModel  ->  vocabSize per position)
 */
// A simple value object (a "data bag") that holds ALL the
// learnable numbers in the model. It is "readonly": once created,
// it cannot be changed. To get a new version, use withUpdate() or
// build a new Weights from the data array.
final readonly class Weights
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public array $data)
    {
    }

    /**
     * An empty Weights object. Useful as a starting point before
     * real weights are loaded.
     */
    public static function empty(): self
    {
        return new self([
            'tokenEmbed' => [],
            'posEmbed' => [],
            'attn' => [],
            'ffn' => [],
            'final' => [],
        ]);
    }

    /**
     * Read a single weight using a dotted path like "attn.0.wq".
     * We split the path on "." and walk one step at a time.
     * This is just a convenient lookup; if a part of the path
     * is missing, we throw.
     */
    public function get(string $path): mixed
    {
        $segments = explode('.', $path);
        $cursor = $this->data;
        foreach ($segments as $segment) {
            if (!\is_array($cursor) || !\array_key_exists($segment, $cursor)) {
                throw new \OutOfBoundsException("No weights at path $path.");
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Check if a dotted path exists. Same as get() but never throws.
     */
    public function hasPath(string $path): bool
    {
        try {
            $this->get($path);

            return true;
        } catch (\OutOfBoundsException) {
            return false;
        }
    }

    /**
     * Return a new Weights where the value at $path is replaced.
     * We deep-copy the array first (because PHP arrays are
     // shared by value but nested arrays would be shared by
     // reference if we used references directly).
     */
    public function withUpdate(string $path, mixed $value): self
    {
        $segments = explode('.', $path);
        $data = $this->data;
        $cursor = &$data;
        foreach ($segments as $i => $segment) {
            if (!\is_array($cursor)) {
                throw new \RuntimeException("Cannot traverse into non-array at path $path.");
            }
            if ($i === \count($segments) - 1) {
                $cursor[$segment] = $value;

                break;
            }
            if (!isset($cursor[$segment]) || !\is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        unset($cursor);

        return new self($data);
    }

    /**
     * Check if two weight bags are EXACTLY equal (same numbers, same
     * shape). Useful in tests when we know the answer should match.
     */
    public function equals(self $other): bool
    {
        return $this->deepEquals($this->data, $other->data);
    }

    /**
     * Like equals(), but allows tiny floating-point differences.
     * Because float math has rounding errors, this is the version
     * you usually want in tests.
     */
    public function approxEquals(self $other, float $eps = 1e-7): bool
    {
        return $this->deepApproxEquals($this->data, $other->data, $eps);
    }

    /**
     * Walk two nested arrays side by side and check that they have
     * the same keys and equal values.
     */
    private function deepEquals(mixed $a, mixed $b): bool
    {
        if (\is_array($a) && \is_array($b)) {
            if (\count($a) !== \count($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (!\array_key_exists($k, $b)) {
                    return false;
                }
                if (!$this->deepEquals($v, $b[$k])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    /**
     * Same as deepEquals, but for floats we allow a tiny error.
     */
    private function deepApproxEquals(mixed $a, mixed $b, float $eps): bool
    {
        if (\is_array($a) && \is_array($b)) {
            if (\count($a) !== \count($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (!\array_key_exists($k, $b)) {
                    return false;
                }
                if (!$this->deepApproxEquals($v, $b[$k], $eps)) {
                    return false;
                }
            }

            return true;
        }

        if (\is_float($a) && \is_float($b)) {
            return \abs($a - $b) <= $eps;
        }

        return $a === $b;
    }
}
