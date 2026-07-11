<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Repository;

use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Model\Weights;

// The repository for LanguageModel aggregate roots.
//
// Weights (the huge collection of numbers in the model) are
// stored separately from the model's metadata (its name, its
// config, its status). That's why there are two pairs of
// save/load methods: one for the model itself and one for
// the weights.
interface LanguageModelRepository
{
    /**
     * Insert or update the model's metadata (no weights).
     */
    public function save(LanguageModel $model): void;

    /**
     * Find a model by id. Returns null if it doesn't exist.
     */
    public function find(ModelId $id): ?LanguageModel;

    /**
     * Every model in the database, newest first.
     *
     * @return list<LanguageModel>
     */
    public function all(): array;

    /**
     * Replace ALL the weights of a model with new ones. The
     * implementation deletes the old weights first and writes
     * the new ones in a single transaction.
     */
    public function saveWeights(ModelId $id, Weights $weights): void;

    /**
     * Load the weights of a model. Throws if the model has
     * no weights saved.
     */
    public function loadWeights(ModelId $id): Weights;

    /**
     * Like find(), but also loads the weights so the model is
     // immediately usable. (This is the slow version because the
     // weights can be big.)
     */
    public function findWithWeights(ModelId $id): ?LanguageModel;
}
