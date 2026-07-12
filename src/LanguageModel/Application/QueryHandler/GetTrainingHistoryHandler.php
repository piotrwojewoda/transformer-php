<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\QueryHandler;

use App\LanguageModel\Application\Query\GetTrainingHistoryQuery;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use App\LanguageModel\HttpInterface\View\TrainingHistoryView;
use App\Shared\Domain\Clock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// Handles the GetTrainingHistoryQuery. We take the most recent
// training job for the model (we only show one history) and
// turn its data into a TrainingHistoryView. Also computes live
// progress info (elapsed, ETA, isLive) for the polling endpoint.
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetTrainingHistoryHandler
{
    public function __construct(
        private TrainingJobRepository $jobs,
        private Clock $clock,
    ) {
    }

    public function __invoke(GetTrainingHistoryQuery $query): ?TrainingHistoryView
    {
        $jobs = $this->jobs->findByModel($query->modelId);
        if ($jobs === []) {
            return null;
        }
        $job = $jobs[0];
        // The (epoch, loss) points, ordered by epoch.
        $points = $this->jobs->lossHistory($job->id);
        $lastLoss = $job->lastLoss()?->value;

        // Compute progress info for the live polling endpoint.
        $startedAt = $job->startedAt();
        $elapsed = null;
        $estimated = null;
        if ($startedAt !== null) {
            $now = $this->clock->now();
            $elapsed = (float) ($now->getTimestamp() - $startedAt->getTimestamp());
            $epoch = $job->epoch();
            if ($epoch > 0 && $epoch < $job->config->totalEpochs) {
                $estimated = ($elapsed / $epoch) * ($job->config->totalEpochs - $epoch);
            }
        }
        $isLive = \in_array($job->status()->value, ['queued', 'running'], true);

        return TrainingHistoryView::fromParts(
            jobId: $job->id->value,
            status: $job->status()->value,
            totalEpochs: $job->config->totalEpochs,
            currentEpoch: $job->epoch(),
            lastLoss: $lastLoss,
            points: $points,
            startedAt: $startedAt,
            elapsedSeconds: $elapsed,
            estimatedRemainingSeconds: $estimated,
            isLive: $isLive,
            errorMessage: $job->errorMessage(),
        );
    }
}
