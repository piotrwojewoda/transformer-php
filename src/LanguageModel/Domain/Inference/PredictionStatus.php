<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Inference;

// The lifecycle of a single prediction (text generation) request.
// Same shape as TrainingStatus: queued -> running -> done/failed.
enum PredictionStatus: string
{
    // The request was created and is waiting for a worker.
    case Queued = 'queued';
    // A worker is currently generating the text.
    case Running = 'running';
    // The text was generated successfully.
    case Done = 'done';
    // Something went wrong (an error, a missing model, etc).
    case Failed = 'failed';
}
