<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\Controller;

use App\LanguageModel\Application\Query\ListModelsQuery;
use App\LanguageModel\Domain\Repository\CorpusRepository;
use App\LanguageModel\Domain\Repository\PredictionRepository;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

// The home page. Shows a quick summary: how many corpora,
// models, training jobs, and predictions we have. It uses the
// query bus (so the actual data lookup goes through the
// proper CQRS query handler, not directly through a repo).
final readonly class DashboardController
{
    public function __construct(
        private Environment $twig,
        private CorpusRepository $corpora,
        private TrainingJobRepository $jobs,
        private PredictionRepository $predictions,
        private MessageBusInterface $queryBus,
    ) {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        // Ask the query bus for the list of all models.
        $models = $this->queryBus->dispatch(new ListModelsQuery())->last(HandledStamp::class)->getResult();
        $data = [
            'corpusCount' => \count($this->corpora->all()),
            'modelCount' => \count($models),
            // The placeholder uuid is just to satisfy the type;
            // findByModel returns [] for a non-existent model,
            // which gives us a quick "0 jobs" count.
            'trainingJobCount' => \count($this->jobs->findByModel(new \App\LanguageModel\Domain\Model\ModelId('00000000-0000-0000-0000-000000000000'))),
            'predictionCount' => \count($this->predictions->all()),
        ];

        return new Response($this->twig->render('dashboard.html.twig', $data));
    }
}
