<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\View;

// A flat view of a training run, used by the loss-curve graph
// and the live progress bar on the model detail page. Carries
// the most recent job's status, loss series, and progress info.
final readonly class TrainingHistoryView
{
    /**
     * @param list<array{epoch: int, loss: float}> $points
     */
    public function __construct(
        public string $jobId,
        public string $status,
        public int $totalEpochs,
        public int $currentEpoch,
        public ?float $lastLoss,
        public array $points,
        public ?\DateTimeImmutable $startedAt,
        public ?float $elapsedSeconds,
        public ?float $estimatedRemainingSeconds,
        public bool $isLive,
        public ?string $errorMessage = null,
    ) {
    }

    /**
     * Build a view from its individual parts. Using named
     // arguments is clearer than a long constructor call.
     *
     * @param list<array{epoch: int, loss: float}> $points
     */
    public static function fromParts(
        string $jobId,
        string $status,
        int $totalEpochs,
        int $currentEpoch,
        ?float $lastLoss,
        array $points,
        ?\DateTimeImmutable $startedAt = null,
        ?float $elapsedSeconds = null,
        ?float $estimatedRemainingSeconds = null,
        bool $isLive = false,
        ?string $errorMessage = null,
    ): self {
        return new self($jobId, $status, $totalEpochs, $currentEpoch, $lastLoss, $points, $startedAt, $elapsedSeconds, $estimatedRemainingSeconds, $isLive, $errorMessage);
    }
}
