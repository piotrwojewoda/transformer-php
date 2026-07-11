<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Model;

use App\LanguageModel\Domain\Event\ModelCreated;
use App\LanguageModel\Domain\Event\ModelFailed;
use App\LanguageModel\Domain\Event\ModelTrained;
use App\LanguageModel\Domain\Event\TrainingStarted;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Clock;

// "AGGREGATE ROOT" means this is the main object of a small
// business concept (here: a language model). It owns its data and
// guards its rules. All the state changes go through methods on
// this class, so we can be sure the rules are followed.
final class LanguageModel extends AggregateRoot
{
    public function __construct(
        public readonly ModelId $id,
        public string $name,
        public readonly ModelConfig $config,
        private ModelStatus $status,
        public readonly \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        private ?Weights $weights = null,
    ) {
    }

    /**
     * Create a brand-new model in "Draft" status.
     * A model starts as a draft because it has no weights yet.
     * The application layer (CreateModelHandler) then initializes
     * random weights and calls markReady().
     */
    public static function create(string $name, ModelConfig $config, Clock $clock): self
    {
        $now = $clock->now();
        $model = new self(
            ModelId::create(),
            $name,
            $config,
            ModelStatus::Draft,
            $now,
            $now,
        );
        // Record a "model was created" event. Other parts of the
        // system (like a projector that updates a read model) can
        // listen for this event.
        $model->recordThat(new ModelCreated($model->id, $config));

        return $model;
    }

    public function status(): ModelStatus
    {
        return $this->status;
    }

    public function weights(): ?Weights
    {
        return $this->weights;
    }

    public function setWeights(Weights $weights): void
    {
        $this->weights = $weights;
    }

    /**
     * Move the model from "Draft" to "Ready".
     * This happens after the trainer has created the initial random
     * weights and saved them. Only valid from Draft.
     */
    public function markReady(Clock $clock): void
    {
        if ($this->status !== ModelStatus::Draft) {
            throw new \DomainException(
                "Cannot mark ready from status {$this->status->value}."
            );
        }
        $this->status = ModelStatus::Ready;
        $this->updatedAt = $clock->now();
    }

    /**
     * Move the model from "Ready" to "Training".
     * We can only start training a model that has finished being
     * prepared. The status guard makes that rule impossible to break.
     */
    public function startTraining(Clock $clock): void
    {
        if ($this->status !== ModelStatus::Ready) {
            throw new \DomainException(
                "Cannot start training from status {$this->status->value}."
            );
        }
        $this->status = ModelStatus::Training;
        $this->updatedAt = $clock->now();
        $this->recordThat(new TrainingStarted($this->id));
    }

    /**
     * Replace the weights with the new ones produced by one
     * optimizer step. Only valid while Training.
     */
    public function applyGradient(Weights $newWeights, Clock $clock): void
    {
        if ($this->status !== ModelStatus::Training) {
            throw new \DomainException(
                "Cannot apply gradient from status {$this->status->value}; model must be in Training."
            );
        }
        $this->weights = $newWeights;
        $this->updatedAt = $clock->now();
    }

    /**
     * Mark the model as fully trained. Only valid while Training.
     * Records a ModelTrained event with the final loss so the UI
     * can show how well training went.
     */
    public function markTrained(int $totalEpochs, float $finalLoss, Clock $clock): void
    {
        if ($this->status !== ModelStatus::Training) {
            throw new \DomainException(
                "Cannot mark trained from status {$this->status->value}."
            );
        }
        $this->status = ModelStatus::Trained;
        $this->updatedAt = $clock->now();
        $this->recordThat(new ModelTrained($this->id, $totalEpochs, $finalLoss));
    }

    /**
     * Mark the model as broken. We can fail from any state, because
     * things can always go wrong (an exception, a worker crash, etc).
     */
    public function markFailed(string $reason, Clock $clock): void
    {
        $this->status = ModelStatus::Failed;
        $this->updatedAt = $clock->now();
        $this->recordThat(new ModelFailed($this->id, $reason));
    }
}
