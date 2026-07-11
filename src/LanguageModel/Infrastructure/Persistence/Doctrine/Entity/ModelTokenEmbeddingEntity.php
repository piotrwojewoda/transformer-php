<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per single number in the token embedding table.
// The whole table for a model has (vocabSize x dModel) rows.
// Storing it this way (instead of as one giant BLOB) means
// we can SELECT individual weights with plain SQL.
class ModelTokenEmbeddingEntity
{
    public int $modelId = 0;
    public int $tokenId = 0;
    public int $dim = 0;
    public float $value = 0.0;
}
