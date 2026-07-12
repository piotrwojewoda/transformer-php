<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\QueryHandler;

use App\LanguageModel\Application\Query\ListPredictionsQuery;
use App\LanguageModel\Domain\Repository\PredictionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// Handles the ListPredictionsQuery. Loads every prediction and
// returns it so the UI can render the list.
#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListPredictionsHandler
{
    public function __construct(private PredictionRepository $predictions)
    {
    }

    /** @return list<\App\LanguageModel\Domain\Inference\Prediction> */
    public function __invoke(ListPredictionsQuery $query): array
    {
        return $this->predictions->all();
    }
}
