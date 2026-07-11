<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\View;

use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Model\ModelStatus;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;

// A "view" is a flat object the UI can render directly. We
// build it from a LanguageModel (the rich domain object) and a
// TrainingJobRepository (so we can include the most-recent loss).
//
// "readonly" means: once created, no field can be changed.
final readonly class ModelView
{
    public function __construct(
        public string $id,
        public string $name,
        public ModelStatus $status,
        public int $dModel,
        public int $numHeads,
        public int $numLayers,
        public int $dFf,
        public int $maxSeqLen,
        public int $vocabSize,
        // The most recent loss across all of this model's jobs,
        // or null if it has never been trained.
        public ?float $lastLoss,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Build a ModelView from a domain LanguageModel.
     */
    public static function from(LanguageModel $model, TrainingJobRepository $jobs): self
    {
        $lastLoss = null;
        // Walk all jobs for this model and remember the last
        // loss value we saw.
        foreach ($jobs->findByModel($model->id) as $job) {
            $loss = $job->lastLoss();
            if ($loss !== null) {
                $lastLoss = $loss->value;
            }
        }

        return new self(
            id: $model->id->value,
            name: $model->name,
            status: $model->status(),
            dModel: $model->config->dModel,
            numHeads: $model->config->numHeads,
            numLayers: $model->config->numLayers,
            dFf: $model->config->dFf,
            maxSeqLen: $model->config->maxSeqLen,
            vocabSize: $model->config->vocabSize,
            lastLoss: $lastLoss,
            createdAt: $model->createdAt,
            updatedAt: $model->updatedAt,
        );
    }

    public function modelId(): ModelId
    {
        return ModelId::fromString($this->id);
    }

    /**
     * How many individual numbers are stored in the database
     // for this model's weights. Useful for showing a
     // "this model has 1234 weights" stat.
     */
    public function totalWeightRows(): int
    {
        $attn = 4 * $this->dModel * $this->dModel * $this->numLayers;
        $ffnPer = $this->dModel * $this->dFf + $this->dFf + $this->dFf * $this->dModel + $this->dModel;
        $ffn = $ffnPer * $this->numLayers;
        $emb = $this->vocabSize * $this->dModel + $this->maxSeqLen * $this->dModel;
        $final = $this->vocabSize * $this->dModel;

        return $attn + $ffn + $emb + $final;
    }
}
