<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Query;

use App\LanguageModel\Domain\Model\ModelId;

// "Please give me the training history (loss over time) for
// this model".
final readonly class GetTrainingHistoryQuery
{
    public function __construct(public ModelId $modelId)
    {
    }
}
