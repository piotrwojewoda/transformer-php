<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per single number in the LayerNorm parameter
// vectors (gamma and beta for both attention and FFN).
// The "which" column picks the right vector: lnAttnGamma,
// lnAttnBeta, lnFfnGamma, or lnFfnBeta.
class ModelLayerNormEntity
{
    public int $modelId = 0;
    public int $layer = 0;
    public string $which = 'lnAttnGamma';
    public int $dim = 0;
    public float $value = 0.0;
}
