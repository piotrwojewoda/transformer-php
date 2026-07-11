<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\CommandHandler;

use App\LanguageModel\Application\Command\TrainModelCommand;
use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use App\LanguageModel\Domain\Training\TrainingJob;
use App\LanguageModel\Domain\Training\TrainingJobId;
use App\LanguageModel\Infrastructure\Messenger\Message\TrainModelMessage;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventCollector;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

// Handles the TrainModelCommand. The handler does the "cheap"
// part of the work: validate the model, create a TrainingJob,
// and hand the actual training off to a background worker
// (via a TrainModelMessage on the message bus).
#[AsMessageHandler]
final readonly class TrainModelHandler
{
    public function __construct(
        private LanguageModelRepository $models,
        private TrainingJobRepository $jobs,
        private Clock $clock,
        private DomainEventCollector $events,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(TrainModelCommand $command): TrainingJobId
    {
        // Find the model. If it doesn't exist, that's a 404, not a
        // validation error, so we throw RuntimeException.
        $model = $this->models->find($command->modelId);
        if ($model === null) {
            throw new \RuntimeException("Model {$command->modelId->value} not found.");
        }
        // We can only start training a model that already has its
        // random weights set up. (Draft = no weights yet.)
        if ($model->status()->name === 'Draft') {
            throw new \DomainException(
                "Model {$command->modelId->value} is in Draft status; mark it Ready before training."
            );
        }

        // Create a new TrainingJob aggregate in "Queued" status.
        $job = TrainingJob::queue($command->modelId, $command->config, $this->clock);
        $this->jobs->save($job);

        // Forward the job's events to the collector.
        $this->events->recordAll($job->pullDomainEvents());

        // Put a message on the bus so a worker picks it up. The
        // worker will run the actual training, one epoch per
        // message (so the worker can be killed and resumed).
        $this->commandBus->dispatch(new TrainModelMessage($job->id, $command->modelId));

        return $job->id;
    }
}
