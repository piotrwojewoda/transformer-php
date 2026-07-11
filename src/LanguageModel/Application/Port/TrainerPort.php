<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Port;

use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\Weights;
use App\LanguageModel\Domain\Token\TokenSequence;
use App\LanguageModel\Domain\Training\TrainingConfig;

// The "trainer" is the thing that knows how to teach a model.
// The application layer only sees this interface; the real
// implementation (ModelTrainer) lives in Infrastructure and
// knows about tensors, gradient descent, etc.
interface TrainerPort
{
    /**
     * Create a fresh set of random weights for a model with
     // the given shape.
     */
    public function initializeWeights(ModelConfig $config): Weights;

    /**
     * Train for one epoch on the given tokenized data and
     // return the new weights.
     */
    public function trainOneEpoch(
        LanguageModel $model,
        TokenSequence $data,
        TrainingConfig $config,
    ): Weights;
}
