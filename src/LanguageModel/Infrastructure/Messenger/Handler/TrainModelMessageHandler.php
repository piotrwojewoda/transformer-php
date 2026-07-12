<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Messenger\Handler;

use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Repository\CorpusRepository;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Domain\Repository\AdamStateRepository;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use App\LanguageModel\Domain\Repository\VocabularyRepository;
use App\LanguageModel\Domain\Token\TokenSequence;
use App\LanguageModel\Domain\Training\TrainingJob;
use App\LanguageModel\Domain\Training\TrainingLoss;
use App\LanguageModel\Infrastructure\Messenger\Message\TrainModelMessage;
use App\LanguageModel\Infrastructure\Transformer\ModelTrainer;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventCollector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

// This handler is the worker side of the training pipeline.
// It runs in a background process, picking TrainModelMessage
// items off the async_training queue.
//
// The "AsMessageHandler" attribute tells Symfony Messenger to
// automatically register this class as a handler for that
// message type.
#[AsMessageHandler]
final readonly class TrainModelMessageHandler
{
    public function __construct(
        private LanguageModelRepository $models,
        private TrainingJobRepository $jobs,
        private CorpusRepository $corpora,
        private VocabularyRepository $vocabularies,
        private ModelTrainer $trainer,
        private MessageBusInterface $commandBus,
        private Clock $clock,
        private DomainEventCollector $events,
        private AdamStateRepository $adamStates,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(TrainModelMessage $message): void
    {
        // Find the job. If it's gone (maybe deleted by a test or
        // a manual cleanup), there's nothing to do.
        $job = $this->jobs->find($message->jobId);
        if ($job === null) {
            $this->logger?->warning('TrainModelMessage received for unknown job', ['jobId' => $message->jobId->value]);

            return;
        }
        // If the job is already done or failed, ignore the message.
        // This can happen if two workers pick up the same message.
        if ($job->status()->value === 'done' || $job->status()->value === 'failed') {
            $this->logger?->info('TrainModelMessage ignored: job already terminal', [
                'jobId' => $message->jobId->value,
                'status' => $job->status()->value,
            ]);

            return;
        }

        // If the job's epochs are already complete (possible after a
        // crash during markTrained), finish the model and skip training.
        if ($job->epoch() >= $job->config->totalEpochs) {
            $model = $this->models->findWithWeights($message->modelId);
            if ($model !== null && $model->status()->value === 'trained') {
                $model->resetToReady($this->clock);
            }
            if ($model !== null && $model->status()->value === 'ready') {
                $model->startTraining($this->clock);
            }
            if ($model !== null) {
                $model->markTrained($job->config->totalEpochs, $job->lastLoss()?->value ?? 0.0, $this->clock);
                $this->models->save($model);
                $this->events->recordAll($model->pullDomainEvents());
            }
            $job->complete($this->clock);
            $this->jobs->save($job);
            $this->events->recordAll($job->pullDomainEvents());

            return;
        }

        // Load the model WITH its weights. If the model is gone,
        // fail the job and stop.
        $model = $this->models->findWithWeights($message->modelId);
        if ($model === null) {
            $job->fail("Model {$message->modelId->value} disappeared.", $this->clock);
            $this->jobs->save($job);
            $this->events->recordAll($job->pullDomainEvents());

            return;
        }

        // Find corpora to train on inside the chosen category.
        $corpora = $this->corpora->findByCategory($message->categoryId);
        if ($corpora === []) {
            $job->fail('No corpora available in the selected category for training.', $this->clock);
            $this->jobs->save($job);
            $this->events->recordAll($job->pullDomainEvents());

            return;
        }
        // Concatenate all corpora in the category, newest first.
        $corpus = $corpora[0];
        $rawText = implode("\n\n", array_map(fn ($c) => $c->rawText, $corpora));
        $vocab = $this->vocabularies->findByCorpus($corpus->id);
        if ($vocab === null) {
            $job->fail('No vocabulary available for the corpus.', $this->clock);
            $this->jobs->save($job);
            $this->events->recordAll($job->pullDomainEvents());

            return;
        }

        // Turn the raw text into a TokenSequence (one token per
        // character).
        $tokenized = $vocab->encode($rawText);

        // On the very first message (job is queued), transition both
        // the job and the model into their running state.
        if ($job->status()->value === 'queued') {
            $job->start($this->clock);
            if ($model->status()->value === 'trained') {
                $model->resetToReady($this->clock);
            }
            $model->startTraining($this->clock);
            $this->jobs->save($job);
            $this->models->save($model);
            $this->events->recordAll($job->pullDomainEvents());
            $this->events->recordAll($model->pullDomainEvents());
        }

        try {
            $epoch = $job->epoch();
            $this->trainOneEpoch($model, $job, $tokenized, $epoch);
        } catch (\Throwable $e) {
            // Log the error. The doctrine_transaction middleware will
            // roll back the transaction and close the EntityManager.
            // The message will be retried per the transport's retry
            // strategy, and eventually moved to the failed transport.
            $this->logger?->error('Training epoch failed', [
                'jobId' => $message->jobId->value,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // If we've done all the epochs, mark the job and the
        // model as complete.
        if ($job->epoch() >= $job->config->totalEpochs) {
            $job->complete($this->clock);
            $model->markTrained($job->config->totalEpochs, $job->lastLoss()?->value ?? 0.0, $this->clock);
            $this->models->save($model);
            $this->jobs->save($job);
            $this->events->recordAll($job->pullDomainEvents());
            $this->events->recordAll($model->pullDomainEvents());

            return;
        }
        // Otherwise, queue up another epoch by re-dispatching the
        // same kind of message. This is what makes training
        // "resumable": kill the worker and the next one will
        // pick up where we left off.
        $this->commandBus->dispatch(new TrainModelMessage($job->id, $message->modelId, $message->categoryId));
    }

    /**
     * Run one training pass: ask the trainer for new weights,
     // save them, and record the new loss in the job's history.
     */
    private function trainOneEpoch(
        LanguageModel $model,
        TrainingJob $job,
        TokenSequence $tokenized,
        int $epoch,
    ): void {
        $weights = $model->weights();
        if ($weights === null) {
            throw new \RuntimeException('Model has no weights; cannot train.');
        }

        // Load the Adam state from the previous epoch (if any) so
        // the optimizer can build momentum across multiple steps.
        $adamState = $this->adamStates->loadState($model->id);
        $newWeights = $this->trainer->trainOneEpoch($model, $tokenized, $job->config, $adamState);
        $this->models->saveWeights($model->id, $newWeights);

        // Persist the updated Adam state for the next epoch.
        $newAdamState = $this->trainer->lastAdamState;
        if ($newAdamState !== null) {
            $this->adamStates->saveState($model->id, $newAdamState);
        }

        $loss = $this->trainer->lastLoss ?? new TrainingLoss(0.0);
        $job->recordEpoch($epoch, $loss, $this->clock);
        $this->jobs->save($job);
        $this->jobs->recordEpoch($job->id, $epoch, $loss);
    }
}
