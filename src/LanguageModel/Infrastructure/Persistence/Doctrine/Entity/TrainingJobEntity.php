<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per training job. Mirrors the TrainingJob aggregate
// plus a few bookkeeping fields (learning rate, seq len, etc.)
// so we can show them in the UI.
class TrainingJobEntity
{
    public ?int $id = null;
    public string $uuid = '';
    public int $modelId = 0;
    public string $status = 'queued';
    public int $totalEpochs = 0;
    public float $learningRate = 0.0;
    public int $seqLen = 0;
    public int $batchSize = 1;
    public int $epoch = 0;
    // The most recent loss (denormalized for fast UI display).
    public ?float $loss = null;
    public ?\DateTimeImmutable $startedAt = null;
    public ?\DateTimeImmutable $finishedAt = null;
    public ?string $errorMessage = null;
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
