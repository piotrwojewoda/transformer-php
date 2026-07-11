<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Command;

use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Training\TrainingConfig;

// A "command" in CQRS is a small message saying "please do
// something". This one says: "please create a new model". The
// matching handler (CreateModelHandler) does the actual work.
//
// We keep commands as plain data objects so they're easy to
// send over a queue, store in a log, or use in tests.
final readonly class CreateModelCommand
{
    public function __construct(
        public string $name,
        public ModelConfig $config,
        public TrainingConfig $training,
    ) {
        // Tiny validations so bad input is caught early.
        if (mb_strlen($name, 'UTF-8') === 0) {
            throw new \InvalidArgumentException('Model name cannot be empty.');
        }
        if (mb_strlen($name, 'UTF-8') > 120) {
            throw new \InvalidArgumentException('Model name cannot exceed 120 characters.');
        }
    }
}
