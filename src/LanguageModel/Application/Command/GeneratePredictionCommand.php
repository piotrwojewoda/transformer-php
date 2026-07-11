<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Command;

use App\LanguageModel\Domain\Inference\SamplingConfig;
use App\LanguageModel\Domain\Model\ModelId;

// "Please generate text starting from this prompt using this
// model and these sampling settings". The handler will:
//   1. Check the model is ready.
//   2. Create a Prediction aggregate (still in "Queued" state).
//   3. Dispatch a GeneratePredictionMessage so a worker can
//      actually run the model.
final readonly class GeneratePredictionCommand
{
    public function __construct(
        public ModelId $modelId,
        public string $prompt,
        public SamplingConfig $sampling,
    ) {
    }
}
