<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\CommandHandler;

use App\LanguageModel\Application\Command\GeneratePredictionCommand;
use App\LanguageModel\Domain\Inference\Prediction;
use App\LanguageModel\Domain\Inference\PredictionId;
use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Domain\Repository\PredictionRepository;
use App\LanguageModel\Infrastructure\Messenger\Message\GeneratePredictionMessage;
use App\Shared\Domain\Clock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

// Handles the GeneratePredictionCommand. We do the "cheap" part
// here (validate, create the Prediction aggregate, dispatch a
// message) and let a background worker do the actual text
// generation.
#[AsMessageHandler]
final readonly class GeneratePredictionHandler
{
    public function __construct(
        private LanguageModelRepository $models,
        private PredictionRepository $predictions,
        private Clock $clock,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(GeneratePredictionCommand $command): PredictionId
    {
        $model = $this->models->find($command->modelId);
        if ($model === null) {
            throw new \RuntimeException("Model {$command->modelId->value} not found.");
        }
        // Predictions need a model with weights: either "Ready"
        // (initialized but not yet trained) or "Trained". Other
        // statuses are not valid.
        $status = $model->status()->name;
        if (!\in_array($status, ['Ready', 'Trained'], true)) {
            throw new \DomainException(
                "Model {$command->modelId->value} is in {$status} status; predictions require Ready or Trained."
            );
        }

        // Create the Prediction aggregate in "Queued" status.
        $prediction = Prediction::queue($command->modelId, $command->prompt, $command->sampling, $this->clock);
        $this->predictions->save($prediction);

        // Hand off to a worker. The worker will call the predictor
        // and store the generated text.
        $this->commandBus->dispatch(new GeneratePredictionMessage($prediction->id, $command->modelId));

        return $prediction->id;
    }
}
