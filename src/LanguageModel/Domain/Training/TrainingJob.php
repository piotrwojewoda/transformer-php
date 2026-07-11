<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Training;

use App\LanguageModel\Domain\Event\EpochCompleted;
use App\LanguageModel\Domain\Event\TrainingFailed;
use App\LanguageModel\Domain\Event\TrainingQueued;
use App\LanguageModel\Domain\Model\ModelId;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Clock;

// A training job is the "task" of training one model. It's an
// aggregate root, so all the rules about its state live here.
final class TrainingJob extends AggregateRoot
{
    // Internal state, kept private so callers must go through the
    // methods below to change anything.
    private TrainingStatus $status;
    // How many epochs we've finished so far (0 = none yet).
    private int $epoch = 0;
    // The most recent loss number we saw.
    private ?TrainingLoss $lastLoss = null;
    // When a worker actually picked up the job and started it.
    private ?\DateTimeImmutable $startedAt = null;
    // When the job ended, one way or another.
    private ?\DateTimeImmutable $finishedAt = null;
    // Human-readable reason for failure (null while still running).
    private ?string $errorMessage = null;

    public function __construct(
        public readonly TrainingJobId $id,
        public readonly ModelId $modelId,
        public readonly TrainingConfig $config,
        public readonly \DateTimeImmutable $createdAt,
    ) {
        $this->status = TrainingStatus::Queued;
    }

    /**
     * Create a new training job that hasn't started yet. The job
     * is born in "Queued" status, waiting for a worker.
     */
    public static function queue(ModelId $modelId, TrainingConfig $config, Clock $clock): self
    {
        $job = new self(
            TrainingJobId::create(),
            $modelId,
            $config,
            $clock->now(),
        );
        // Let the outside world know a new job has been queued.
        $job->recordThat(new TrainingQueued($job->id, $modelId, $config->totalEpochs));

        return $job;
    }

    /**
     * Rebuild a job from saved database fields. Used when we load
     * a job back from the database (for example, to resume after
     * a worker restart).
     */
    public static function reconstruct(
        TrainingJobId $id,
        ModelId $modelId,
        TrainingConfig $config,
        \DateTimeImmutable $createdAt,
        TrainingStatus $status,
        int $epoch,
        ?TrainingLoss $lastLoss,
        ?\DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $finishedAt,
        ?string $errorMessage,
    ): self {
        $job = new self($id, $modelId, $config, $createdAt);
        $job->status = $status;
        $job->epoch = $epoch;
        $job->lastLoss = $lastLoss;
        $job->startedAt = $startedAt;
        $job->finishedAt = $finishedAt;
        $job->errorMessage = $errorMessage;

        return $job;
    }

    public function status(): TrainingStatus
    {
        return $this->status;
    }

    public function epoch(): int
    {
        return $this->epoch;
    }

    public function lastLoss(): ?TrainingLoss
    {
        return $this->lastLoss;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
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
     * Mark the job as running. We can only start a job that's
     // queued. The status guard makes it impossible to start twice.
     */
    public function start(Clock $clock): void
    {
        if ($this->status !== TrainingStatus::Queued) {
            throw new \DomainException("Cannot start from status {$this->status->value}.");
        }
        $this->status = TrainingStatus::Running;
        $this->startedAt = $clock->now();
    }

    /**
     * Record that an epoch just finished. We also bump the counter
     // and save the loss. The epoch argument must match the one
     // we expect next; this catches out-of-order epoch numbers.
     */
    public function recordEpoch(int $epoch, TrainingLoss $loss, Clock $clock): void
    {
        if ($this->status !== TrainingStatus::Running) {
            throw new \DomainException("Cannot record epoch from status {$this->status->value}.");
        }
        if ($epoch !== $this->epoch) {
            throw new \DomainException("Epoch must be sequential; expected {$this->epoch}, got $epoch.");
        }
        if ($epoch >= $this->config->totalEpochs) {
            throw new \DomainException("Epoch $epoch exceeds totalEpochs {$this->config->totalEpochs}.");
        }
        $this->epoch = $epoch + 1;
        $this->lastLoss = $loss;
        $this->recordThat(new EpochCompleted($this->modelId, $epoch, $loss->value));
    }

    /**
     * Mark the job as successfully completed. We can only do this
     // if we have done all the epochs.
     */
    public function complete(Clock $clock): void
    {
        if ($this->status !== TrainingStatus::Running) {
            throw new \DomainException("Cannot complete from status {$this->status->value}.");
        }
        if ($this->epoch < $this->config->totalEpochs) {
            throw new \DomainException("Cannot complete: only {$this->epoch} of {$this->config->totalEpochs} epochs done.");
        }
        $this->status = TrainingStatus::Done;
        $this->finishedAt = $clock->now();
    }

    /**
     * Mark the job as failed. We can fail from any state, because
     // things can always go wrong.
     */
    public function fail(string $reason, Clock $clock): void
    {
        $this->status = TrainingStatus::Failed;
        $this->finishedAt = $clock->now();
        $this->errorMessage = $reason;
        $this->recordThat(new TrainingFailed($this->id, $reason));
    }
}
