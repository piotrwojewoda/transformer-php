<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\View;

use App\LanguageModel\Domain\Inference\Prediction;

// A flat view of a Prediction that the UI can render. Same
// idea as ModelView: take the rich domain object and pull out
// just what the template needs.
final readonly class PredictionView
{
    public function __construct(
        public string $id,
        public string $modelId,
        public string $prompt,
        public string $status,
        public ?string $generatedText,
        public string $samplingStrategy,
        public int $maxNewTokens,
        public ?int $topK,
        public ?string $errorMessage,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $finishedAt,
    ) {
    }

    public static function from(Prediction $prediction): self
    {
        return new self(
            id: $prediction->id->value,
            modelId: $prediction->modelId->value,
            prompt: $prediction->prompt,
            status: $prediction->status()->value,
            generatedText: $prediction->generatedText(),
            samplingStrategy: $prediction->sampling->strategy->value,
            maxNewTokens: $prediction->sampling->maxNewTokens,
            topK: $prediction->sampling->topK,
            errorMessage: $prediction->errorMessage(),
            createdAt: $prediction->createdAt,
            finishedAt: $prediction->finishedAt(),
        );
    }

    /**
     * Has the prediction reached a final state (success or
     // failure)? The UI uses this to decide whether to keep
     // polling or show the final result.
     */
    public function isDone(): bool
    {
        return $this->status === 'done' || $this->status === 'failed';
    }
}
