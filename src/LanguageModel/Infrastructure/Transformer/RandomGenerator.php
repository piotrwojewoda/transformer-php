<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Transformer;

/**
 * Seedable source of pseudo-random numbers used to initialize model
 * parameters and to draw samples. Uses the modern PHP Random\Randomizer
 * API (PHP 8.2+).
 *
 * WHY HAVE OUR OWN RANDOM CLASS?
 * -------------------------------
 * We need random numbers in two places: starting the model weights
 * (where we want the same starting point every time, for testing),
 * and sampling the next token (where we DO want different runs to
 * be different). A seedable random source lets us do both.
 */
final class RandomGenerator
{
    private \Random\Randomizer $randomizer;

    public function __construct(?int $seed = null)
    {
        // If we got a seed, use it. Otherwise build a seed from the
        // current time so each program run starts differently.
        $engine = $seed === null
            ? new \Random\Engine\Mt19937(crc32((string) (microtime(true) * 1000)))
            : new \Random\Engine\Mt19937($seed);
        $this->randomizer = new \Random\Randomizer($engine);
    }

    /**
     * A random integer between $min and $max (inclusive on both ends).
     */
    public function nextInt(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /**
     * A random float in [0.0, 1.0). Useful for probability math.
     */
    public function nextFloat(): float
    {
        return $this->randomizer->getFloat(0.0, 1.0);
    }

    /**
     * Sample one index from a categorical distribution given logits.
     *
     * WHAT IS A CATEGORICAL DISTRIBUTION?
     * Imagine you have a bag with one ball per option, but the balls
     * have different sizes (proportional to exp(logit)). You close
     * your eyes, pick a ball at random, and return its index.
     *
     * "Subtract the max" is a numerical stability trick. Without it,
     * big logits would make exp() return infinity.
     *
     * @param list<float> $logits
     */
    public function sampleCategorical(array $logits): int
    {
        $max = max($logits);
        $exp = [];
        $sum = 0.0;
        foreach ($logits as $l) {
            $e = \exp($l - $max);
            $exp[] = $e;
            $sum += $e;
        }
        // Pick a random number in [0, sum) and see which "ball" it
        // falls into. The bigger a ball, the more likely to be hit.
        $r = $this->nextFloat() * $sum;
        $acc = 0.0;
        foreach ($exp as $i => $e) {
            $acc += $e;
            if ($r <= $acc) {
                return $i;
            }
        }

        return \count($exp) - 1;
    }
}
