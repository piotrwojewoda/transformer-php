<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per single number in the attention matrices
// (Wq, Wk, Wv, Wo). The "matrix" column tells us which
// of the four matrices this row belongs to.
class ModelAttentionWeightEntity
{
    public int $modelId = 0;
    public int $layer = 0;
    public string $matrix = 'wq';
    public int $row = 0;
    public int $col = 0;
    public float $value = 0.0;
}
