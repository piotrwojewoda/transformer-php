<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Messenger\Handler;

use App\LanguageModel\Application\Port\PredictorPort;
use App\LanguageModel\Application\Port\TokenizerPort;
use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Repository\CorpusRepository;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Domain\Repository\PredictionRepository;
use App\LanguageModel\Domain\Repository\VocabularyRepository;
use App\LanguageModel\Infrastructure\Messenger\Message\GeneratePredictionMessage;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventCollector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// The worker side of the prediction pipeline. Picks
// GeneratePredictionMessage items off the async_inference
// queue and generates the requested text.
#[AsMessageHandler]
final readonly class GeneratePredictionMessageHandler
{
    public function __construct(
        private LanguageModelRepository $models,
        private PredictionRepository $predictions,
        private VocabularyRepository $vocabularies,
        private CorpusRepository $corpora,
        private PredictorPort $predictor,
        private TokenizerPort $tokenizer,
        private Clock $clock,
        private DomainEventCollector $events,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(GeneratePredictionMessage $message): void
    {
        // Find the prediction request. If it was deleted, nothing
        // to do.
        $prediction = $this->predictions->find($message->predictionId);
        if ($prediction === null) {
            $this->logger?->warning('GeneratePredictionMessage received for unknown prediction', [
                'predictionId' => $message->predictionId->value,
            ]);

            return;
        }
        // Skip if it's already terminal (handles duplicate messages).
        if ($prediction->status()->name === 'done' || $prediction->status()->name === 'failed') {
            return;
        }

        // Move from Queued to Running.
        $prediction->start();
        $this->predictions->save($prediction);

        // Load the model WITH weights. If it's gone, fail the
        // prediction.
        $model = $this->models->findWithWeights($message->modelId);
        if ($model === null) {
            $prediction->fail("Model {$message->modelId->value} not found.", $this->clock);
            $this->predictions->save($prediction);
            $this->events->recordAll($prediction->pullDomainEvents());

            return;
        }

        // Find a vocabulary. We use the first corpus's vocab.
        $corpora = $this->corpora->all();
        $vocab = null;
        if ($corpora !== []) {
            $vocab = $this->vocabularies->findByCorpus($corpora[0]->id);
        }
        if ($vocab === null) {
            $prediction->fail('No vocabulary available.', $this->clock);
            $this->predictions->save($prediction);
            $this->events->recordAll($prediction->pullDomainEvents());

            return;
        }

        try {
            // Convert prompt -> token ids, run the model, then
            // convert the generated tokens back to text.
            $promptTokens = $vocab->encode($prediction->prompt);
            $generated = $this->predictor->generate($model, $promptTokens, $prediction->sampling);
            $text = $vocab->decode($generated);
        } catch (\Throwable $e) {
            $this->logger?->error('Prediction failed', [
                'predictionId' => $message->predictionId->value,
                'error' => $e->getMessage(),
            ]);
            $prediction->fail($e->getMessage(), $this->clock);
            $this->predictions->save($prediction);
            $this->events->recordAll($prediction->pullDomainEvents());

            return;
        }

        // Success: mark the prediction as done with the text.
        $prediction->complete($text, $this->clock);
        $this->predictions->save($prediction);
        $this->events->recordAll($prediction->pullDomainEvents());
    }
}
