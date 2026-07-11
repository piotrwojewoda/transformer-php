<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Messenger\Message;

use App\LanguageModel\Domain\Inference\PredictionId;
use App\LanguageModel\Domain\Model\ModelId;

// A message that says: "please generate text for this
// prediction request". Workers consume these from the
// async_inference queue and run GeneratePredictionMessageHandler
// for each one.
final readonly class GeneratePredictionMessage
{
    public function __construct(
        public PredictionId $predictionId,
        public ModelId $modelId,
    ) {
    }
}
