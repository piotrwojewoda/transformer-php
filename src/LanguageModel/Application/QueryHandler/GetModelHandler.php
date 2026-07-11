<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\QueryHandler;

use App\LanguageModel\Application\Query\GetModelQuery;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use App\LanguageModel\HttpInterface\View\ModelView;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// Handles the GetModelQuery. Loads the model from the
// repository and turns it into a ModelView (a flatter object
// the UI can render directly).
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetModelHandler
{
    public function __construct(
        private LanguageModelRepository $models,
        private TrainingJobRepository $jobs,
    ) {
    }

    public function __invoke(GetModelQuery $query): ?ModelView
    {
        $model = $this->models->find($query->modelId);
        if ($model === null) {
            return null;
        }

        return ModelView::from($model, $this->jobs);
    }
}
