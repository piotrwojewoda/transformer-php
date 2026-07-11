<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Training;

// The lifecycle of a training job. Same idea as ModelStatus, but
// for a training run instead of the model itself. A job can be
// queued, actively running, finished, or broken.
enum TrainingStatus: string
{
    // The job was created and is waiting for a worker to pick it up.
    case Queued = 'queued';
    // A worker is currently training the model.
    case Running = 'running';
    // All epochs finished without error.
    case Done = 'done';
    // Something went wrong (a crash, an exception, missing data).
    case Failed = 'failed';
}
