<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per (job, epoch, loss) point. Used to draw the
// loss-over-time graph in the UI.
class TrainingLossHistoryEntity
{
    public int $trainingJobId = 0;
    public int $epoch = 0;
    public float $loss = 0.0;

    public function setLoss(float $loss): void
    {
        $this->loss = $loss;
    }
}
