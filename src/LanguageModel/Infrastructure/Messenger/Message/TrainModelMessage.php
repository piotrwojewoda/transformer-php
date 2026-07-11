<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Messenger\Message;

use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Training\TrainingJobId;

// A message that says: "please do one more training pass on
// this model". Workers consume these from the async_training
// queue and run TrainModelMessageHandler for each one.
//
// One message = one epoch. The handler re-dispatches another
// message at the end if there are still epochs left, so
// training can be paused and resumed by simply killing the
// worker (no message means no work).
final readonly class TrainModelMessage
{
    public function __construct(
        public TrainingJobId $jobId,
        public ModelId $modelId,
    ) {
    }
}
