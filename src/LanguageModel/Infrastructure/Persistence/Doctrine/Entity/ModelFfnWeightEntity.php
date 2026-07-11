<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per single number in the FFN matrices. The
// "matrix" column is one of w1, b1, w2, b2.
class ModelFfnWeightEntity
{
    public int $modelId = 0;
    public int $layer = 0;
    public string $matrix = 'w1';
    public int $row = 0;
    public int $col = 0;
    public float $value = 0.0;
}
