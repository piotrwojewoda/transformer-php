<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\QueryHandler;

use App\LanguageModel\Application\Query\ListModelsQuery;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use App\LanguageModel\HttpInterface\View\ModelView;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// Handles the ListModelsQuery. Loads every model and turns
// each one into a ModelView. The UI can then render the list.
#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListModelsHandler
{
    public function __construct(
        private LanguageModelRepository $models,
        private TrainingJobRepository $jobs,
    ) {
    }

    /** @return list<ModelView> */
    public function __invoke(ListModelsQuery $query): array
    {
        $views = [];
        foreach ($this->models->all() as $model) {
            $views[] = ModelView::from($model, $this->jobs);
        }

        return $views;
    }
}
