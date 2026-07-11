<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per single Adam state pair (m, v) for one weight
// element. The "path" is a dotted name like "attn.0.wq".
// Together with (row, col) this uniquely identifies a single
// weight number in the entire model.
class AdamStateEntity
{
    public int $modelId = 0;
    public string $path = '';
    public int $row = 0;
    public int $col = 0;
    public float $m = 0.0;
    public float $v = 0.0;
}
