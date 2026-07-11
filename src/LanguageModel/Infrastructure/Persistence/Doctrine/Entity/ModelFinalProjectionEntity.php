<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per single number in the final projection matrix
// (the "what character comes next" head). Shape is
// (vocabSize x dModel); logits = h @ final.T.
class ModelFinalProjectionEntity
{
    public int $modelId = 0;
    public int $row = 0;
    public int $col = 0;
    public float $value = 0.0;
}
