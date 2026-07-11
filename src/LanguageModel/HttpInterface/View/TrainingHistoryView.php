<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\View;

// A flat view of a training run, used by the loss-curve graph
// on the model detail page. Carries the most recent job's
// status plus a list of (epoch, loss) points.
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
    ): self {
        return new self($jobId, $status, $totalEpochs, $currentEpoch, $lastLoss, $points);
    }
}
