<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per single number in the positional embedding
// table. Same idea as the token embedding: one row per
// (model, position, dim, value) tuple.
class ModelPositionalEmbeddingEntity
{
    public int $modelId = 0;
    public int $position = 0;
    public int $dim = 0;
    public float $value = 0.0;
}
