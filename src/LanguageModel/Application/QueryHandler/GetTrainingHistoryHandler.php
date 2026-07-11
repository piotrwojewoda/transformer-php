<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\QueryHandler;

use App\LanguageModel\Application\Query\GetTrainingHistoryQuery;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use App\LanguageModel\HttpInterface\View\TrainingHistoryView;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// Handles the GetTrainingHistoryQuery. We take the most recent
// training job for the model (we only show one history) and
// turn its data into a TrainingHistoryView.
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetTrainingHistoryHandler
{
    public function __construct(private TrainingJobRepository $jobs)
    {
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

        return TrainingHistoryView::fromParts(
            jobId: $job->id->value,
            status: $job->status()->value,
            totalEpochs: $job->config->totalEpochs,
            currentEpoch: $job->epoch(),
            lastLoss: $lastLoss,
            points: $points,
        );
    }
}
