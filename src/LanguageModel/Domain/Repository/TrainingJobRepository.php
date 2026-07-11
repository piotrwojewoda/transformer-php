<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Repository;

use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Training\TrainingJob;
use App\LanguageModel\Domain\Training\TrainingJobId;
use App\LanguageModel\Domain\Training\TrainingLoss;

// The repository for TrainingJob aggregate roots. It also stores
// a per-epoch loss history (one number per epoch) so we can draw
// a nice loss curve in the UI.
interface TrainingJobRepository
{
    public function save(TrainingJob $job): void;

    public function find(TrainingJobId $id): ?TrainingJob;

    /**
     * Every training job for the given model, newest first.
     *
     * @return list<TrainingJob>
     */
    public function findByModel(ModelId $modelId): array;

    /**
     * Append a new (epoch, loss) point to the job's loss history.
     */
    public function recordEpoch(TrainingJobId $jobId, int $epoch, TrainingLoss $loss): void;

    /**
     * Read the full loss history for a job, ordered by epoch.
     *
     * @return list<array{epoch: int, loss: float}>
     */
    public function lossHistory(TrainingJobId $jobId): array;
}
