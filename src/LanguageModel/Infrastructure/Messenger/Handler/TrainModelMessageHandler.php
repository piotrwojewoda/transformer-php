<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Messenger\Handler;

use App\LanguageModel\Application\Port\TrainerPort;
use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\Weights;
use App\LanguageModel\Domain\Repository\AdamStateRepository;
use App\LanguageModel\Domain\Repository\CorpusRepository;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use App\LanguageModel\Domain\Repository\VocabularyRepository;
use App\LanguageModel\Domain\Token\TokenSequence;
use App\LanguageModel\Domain\Training\TrainingConfig;
use App\LanguageModel\Domain\Training\TrainingJob;
use App\LanguageModel\Domain\Training\TrainingLoss;
use App\LanguageModel\Infrastructure\Messenger\Message\TrainModelMessage;
use App\LanguageModel\Infrastructure\Transformer\Adam;
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
        private AdamStateRepository $adamState,
        private ModelTrainer $trainer,
        private MessageBusInterface $commandBus,
        private Clock $clock,
        private DomainEventCollector $events,
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
        if ($job->status()->name === 'done' || $job->status()->name === 'failed') {
            $this->logger?->info('TrainModelMessage ignored: job already terminal', [
                'jobId' => $message->jobId->value,
                'status' => $job->status()->value,
            ]);

            return;
        }

        // Start the job: queued -> running.
        $job->start($this->clock);
        $this->jobs->save($job);
        $this->events->recordAll($job->pullDomainEvents());

        // Load the model WITH its weights. If the model is gone,
        // fail the job and stop.
        $model = $this->models->findWithWeights($message->modelId);
        if ($model === null) {
            $job->fail("Model {$message->modelId->value} disappeared.", $this->clock);
            $this->jobs->save($job);
            $this->events->recordAll($job->pullDomainEvents());

            return;
        }

        // Find a corpus to train on. We just use the first one
        // (the project only supports one corpus in practice).
        $corpora = $this->corpora->all();
        if ($corpora === []) {
            $job->fail('No corpora available for training.', $this->clock);
            $this->jobs->save($job);
            $this->events->recordAll($job->pullDomainEvents());

            return;
        }
        $corpus = $corpora[0];
        $vocab = $this->vocabularies->findByCorpus($corpus->id);
        if ($vocab === null) {
            $job->fail('No vocabulary available for the corpus.', $this->clock);
            $this->jobs->save($job);
            $this->events->recordAll($job->pullDomainEvents());

            return;
        }

        // Turn the raw text into a TokenSequence (one token per
        // character).
        $tokenized = $vocab->encode($corpus->rawText);

        try {
            $epoch = $job->epoch();
            $this->trainOneEpoch($model, $job->config, $tokenized, $epoch);
        } catch (\Throwable $e) {
            // Anything that goes wrong during training is logged
            // and the job and model are marked as failed.
            $this->logger?->error('Training epoch failed', [
                'jobId' => $message->jobId->value,
                'error' => $e->getMessage(),
            ]);
            $job->fail($e->getMessage(), $this->clock);
            $model->markFailed($e->getMessage(), $this->clock);
            $this->models->save($model);
            $this->jobs->save($job);
            $this->events->recordAll($job->pullDomainEvents());
            $this->events->recordAll($model->pullDomainEvents());

            return;
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
        $this->commandBus->dispatch(new TrainModelMessage($job->id, $message->modelId));
    }

    /**
     * Run one training pass: ask the trainer for new weights,
     // save them, and record the new loss in the job's history.
     */
    private function trainOneEpoch(
        LanguageModel $model,
        TrainingConfig $config,
        TokenSequence $tokenized,
        int $epoch,
    ): void {
        $weights = $model->weights();
        if ($weights === null) {
            throw new \RuntimeException('Model has no weights; cannot train.');
        }
        $newWeights = $this->trainer->trainOneEpoch($model, $tokenized, $config);
        $this->models->saveWeights($model->id, $newWeights);

        $loss = $this->trainer->lastLoss ?? new TrainingLoss(0.0);
        $job = $this->jobs->findByModel($model->id)[0] ?? null;
        if ($job !== null) {
            $job->recordEpoch($epoch, $loss, $this->clock);
            $this->jobs->save($job);
            // Also write a row to the loss history table so the
            // UI can graph it.
            $this->jobs->recordEpoch($job->id, $epoch, $loss);
        }
    }
}
