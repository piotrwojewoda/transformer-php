<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Inference;

use App\LanguageModel\Domain\Event\PredictionFailed;
use App\LanguageModel\Domain\Event\PredictionGenerated;
use App\LanguageModel\Domain\Model\ModelId;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Clock;

// A single text-generation request. The user supplies a prompt
// (e.g. "Once upon a") and the model writes the rest.
final class Prediction extends AggregateRoot
{
    private PredictionStatus $status;
    private ?string $generatedText = null;
    private ?\DateTimeImmutable $finishedAt = null;
    private ?string $errorMessage = null;

    public function __construct(
        public readonly PredictionId $id,
        public readonly ModelId $modelId,
        public readonly string $prompt,
        public readonly SamplingConfig $sampling,
        public readonly \DateTimeImmutable $createdAt,
    ) {
        if (mb_strlen($prompt, 'UTF-8') === 0) {
            throw new \InvalidArgumentException('Prompt cannot be empty.');
        }
        if (mb_strlen($prompt, 'UTF-8') > 500) {
            throw new \InvalidArgumentException('Prompt cannot exceed 500 characters.');
        }
        $this->status = PredictionStatus::Queued;
    }

    /**
     * Create a new prediction request. It starts in "Queued" status
     // and waits for a worker to pick it up.
     */
    public static function queue(
        ModelId $modelId,
        string $prompt,
        SamplingConfig $sampling,
        Clock $clock,
    ): self {
        return new self(
            PredictionId::create(),
            $modelId,
            $prompt,
            $sampling,
            $clock->now(),
        );
    }

    /**
     * Rebuild a prediction from saved database fields. Used when
     // we load it back from the database to check on its status.
     */
    public static function reconstruct(
        PredictionId $id,
        ModelId $modelId,
        string $prompt,
        SamplingConfig $sampling,
        \DateTimeImmutable $createdAt,
        PredictionStatus $status,
        ?string $generatedText,
        ?\DateTimeImmutable $finishedAt,
        ?string $errorMessage,
    ): self {
        $p = new self($id, $modelId, $prompt, $sampling, $createdAt);
        $p->status = $status;
        $p->generatedText = $generatedText;
        $p->finishedAt = $finishedAt;
        $p->errorMessage = $errorMessage;

        return $p;
    }

    public function status(): PredictionStatus
    {
        return $this->status;
    }

    public function generatedText(): ?string
    {
        return $this->generatedText;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Move from Queued to Running. Only valid once.
     */
    public function start(): void
    {
        if ($this->status !== PredictionStatus::Queued) {
            throw new \DomainException("Cannot start from status {$this->status->value}.");
        }
        $this->status = PredictionStatus::Running;
    }

    /**
     * Mark the prediction as successfully done and remember the
     // generated text. Only valid from Running.
     */
    public function complete(string $generatedText, Clock $clock): void
    {
        if ($this->status !== PredictionStatus::Running) {
            throw new \DomainException("Cannot complete from status {$this->status->value}.");
        }
        $this->status = PredictionStatus::Done;
        $this->generatedText = $generatedText;
        $this->finishedAt = $clock->now();
        $this->recordThat(new PredictionGenerated($this->id, $generatedText));
    }

    /**
     * Mark as failed. We can fail from any state because things
     // can always go wrong.
     */
    public function fail(string $reason, Clock $clock): void
    {
        $this->status = PredictionStatus::Failed;
        $this->finishedAt = $clock->now();
        $this->errorMessage = $reason;
        $this->recordThat(new PredictionFailed($this->id, $reason));
    }
}
