<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Command;

use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Training\TrainingConfig;

// "Please queue a training job for this model, using these
// training settings". The handler will:
//   1. Check the model is in a valid state.
//   2. Create a TrainingJob aggregate (still in "Queued" state).
//   3. Dispatch a TrainModelMessage so a worker can pick it up.
final readonly class TrainModelCommand
{
    public function __construct(
        public ModelId $modelId,
        public TrainingConfig $config,
    ) {
    }
}
