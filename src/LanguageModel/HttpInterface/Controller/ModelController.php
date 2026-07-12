<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\Controller;

use App\LanguageModel\Application\Command\CreateModelCommand;
use App\LanguageModel\Application\Command\TrainModelCommand;
use App\LanguageModel\Application\Query\GetModelQuery;
use App\LanguageModel\Domain\Category\CategoryId;
use App\LanguageModel\Application\Query\GetTrainingHistoryQuery;
use App\LanguageModel\Application\Query\ListModelsQuery;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Repository\CategoryRepository;
use App\LanguageModel\Domain\Training\TrainingConfig;
use App\LanguageModel\HttpInterface\Form\CreateModelType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

// The "model" pages: list all models, create a new one, view
// a single one, and trigger training runs.
final readonly class ModelController
{
    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
        private CategoryRepository $categories,
    ) {
    }

    /**
     * Show the list of every model in the system.
     */
    #[Route('/model', name: 'model_list', methods: ['GET'])]
    public function list(): Response
    {
        $models = $this->queryBus->dispatch(new ListModelsQuery())->last(HandledStamp::class)->getResult();

        return new Response($this->twig->render('model/list.html.twig', [
            'models' => $models,
        ]));
    }

    /**
     * Show the "create model" form (GET) and handle the
     // submission (POST). On success we dispatch a
     // CreateModelCommand and redirect to the new model page.
     */
    #[Route('/model/new', name: 'model_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->forms->create(CreateModelType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $id = $this->commandBus->dispatch(new CreateModelCommand(
                $data['name'],
                // Build the value objects from the form data.
                new ModelConfig(
                    (int) $data['dModel'],
                    (int) $data['numHeads'],
                    (int) $data['numLayers'],
                    (int) $data['dFf'],
                    (int) $data['maxSeqLen'],
                    (int) $data['vocabSize'],
                ),
                new TrainingConfig(
                    (float) $data['learningRate'],
                    (int) $data['totalEpochs'],
                    (int) $data['seqLen'],
                ),
            ))->last(HandledStamp::class)?->getResult();

            return new RedirectResponse('/model/'.$id);
        }

        return new Response($this->twig->render('model/new.html.twig', [
            'form' => $form->createView(),
        ]));
    }

    /**
     * Show one model plus its training history. The id must be
     // a UUID.
     */
    #[Route('/model/{id}', name: 'model_show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(string $id): Response
    {
        $modelId = ModelId::fromString($id);
        $view = $this->queryBus->dispatch(new GetModelQuery($modelId))->last(HandledStamp::class)->getResult();
        if ($view === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException("Model $id not found.");
        }
        $history = $this->queryBus->dispatch(new GetTrainingHistoryQuery($modelId))->last(HandledStamp::class)->getResult();

        return new Response($this->twig->render('model/detail.html.twig', [
            'model' => $view,
            'history' => $history,
            'categories' => $this->categories->all(),
        ]));
    }

    /**
     * Return the training progress partial (HTML fragment) so the
     // model detail page can poll it via JS and update just the
     // progress bar without a full page reload.
     */
    #[Route('/model/{id}/training-progress', name: 'model_training_progress', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function trainingProgress(string $id): Response
    {
        $modelId = ModelId::fromString($id);
        $history = $this->queryBus->dispatch(new GetTrainingHistoryQuery($modelId))
            ->last(HandledStamp::class)
            ->getResult();

        return new Response($this->twig->render('model/_training_progress.html.twig', [
            'history' => $history,
        ]));
    }

    /**
     * Trigger ONE more epoch of training. The settings here
     // (lr=0.005, seqLen=32, 1 epoch) are the same defaults
     // used everywhere in the demo.
     */
    #[Route('/model/{id}/train-one-epoch', name: 'model_train_one', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function trainOneEpoch(string $id, Request $request): Response
    {
        $modelId = ModelId::fromString($id);
        $categoryId = CategoryId::fromString((string) $request->request->get('categoryId', ''));
        $this->commandBus->dispatch(new TrainModelCommand(
            $modelId,
            new TrainingConfig(0.005, 1, 32),
            $categoryId,
        ));

        return new RedirectResponse('/model/'.$id);
    }

    /**
     * Trigger N epochs of training. N comes from the form
     // input "n". We force it to be at least 1.
     */
    #[Route('/model/{id}/train-n-epochs', name: 'model_train_n', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function trainN(string $id, Request $request): Response
    {
        $n = max(1, (int) $request->request->get('n', 1));
        $modelId = ModelId::fromString($id);
        $categoryId = CategoryId::fromString((string) $request->request->get('categoryId', ''));
        $this->commandBus->dispatch(new TrainModelCommand(
            $modelId,
            new TrainingConfig(0.005, $n, 32),
            $categoryId,
        ));

        return new RedirectResponse('/model/'.$id);
    }
}
