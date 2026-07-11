<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\QueryHandler;

use App\LanguageModel\Application\Query\GetPredictionQuery;
use App\LanguageModel\Domain\Repository\PredictionRepository;
use App\LanguageModel\HttpInterface\View\PredictionView;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// Handles the GetPredictionQuery. Loads a prediction and
// turns it into a PredictionView.
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetPredictionHandler
{
    public function __construct(private PredictionRepository $predictions)
    {
    }

    public function __invoke(GetPredictionQuery $query): ?PredictionView
    {
        $prediction = $this->predictions->find($query->predictionId);
        if ($prediction === null) {
            return null;
        }

        return PredictionView::from($prediction);
    }
}
