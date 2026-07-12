<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Repository;

use App\LanguageModel\Domain\Inference\Prediction;
use App\LanguageModel\Domain\Inference\PredictionId;
use App\LanguageModel\Domain\Model\ModelId;

// The repository for Prediction aggregate roots. Predictions are
// read-heavy, so we have a "find the most recent N" method that
// the UI uses to show a list of recent generations.
interface PredictionRepository
{
    public function save(Prediction $prediction): void;

    public function find(PredictionId $id): ?Prediction;

    /**
     * Find the most recent predictions for a model, newest first.
     * Defaults to the last 20 (enough for a typical UI list).
     *
     * @return list<Prediction>
     */
    public function findByModel(ModelId $modelId, int $limit = 20): array;

    /**
     * Get every prediction in the database, newest first.
     *
     * @return list<Prediction>
     */
    public function all(): array;
}
