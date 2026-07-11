<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Query;

use App\LanguageModel\Domain\Inference\PredictionId;

// "Please give me the data for this prediction request".
final readonly class GetPredictionQuery
{
    public function __construct(public PredictionId $predictionId)
    {
    }
}
