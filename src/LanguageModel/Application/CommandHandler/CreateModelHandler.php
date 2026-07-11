<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\CommandHandler;

use App\LanguageModel\Application\Command\CreateModelCommand;
use App\LanguageModel\Application\Port\TrainerPort;
use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventCollector;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// A "command handler" is the class that actually does the work
// when a command arrives. This one handles "create a new model".
//
// It coordinates the Domain (creating the LanguageModel) with
// the Infrastructure (saving it to the database). It also
// collects the domain events so the EventRecordingMiddleware can
// dispatch them after the command returns.
#[AsMessageHandler]
final readonly class CreateModelHandler
{
    public function __construct(
        private LanguageModelRepository $models,
        private TrainerPort $trainer,
        private Clock $clock,
        private DomainEventCollector $events,
    ) {
    }

    public function __invoke(CreateModelCommand $command): ModelId
    {
        // 1. Create the aggregate. It starts in "Draft" status.
        $model = LanguageModel::create($command->name, $command->config, $this->clock);
        // 2. Ask the trainer to make a fresh set of random weights.
        $weights = $this->trainer->initializeWeights($command->config);
        // 3. Put the weights on the model.
        $model->setWeights($weights);
        // 4. Move the model to "Ready" status. Now it can be trained.
        $model->markReady($this->clock);

        // 5. Persist the model and its weights.
        $this->models->save($model);
        $this->models->saveWeights($model->id, $weights);

        // 6. Take all the events the model recorded and put them
        //    in the collector so the middleware can dispatch them.
        $this->events->recordAll($model->pullDomainEvents());

        return $model->id;
    }
}
