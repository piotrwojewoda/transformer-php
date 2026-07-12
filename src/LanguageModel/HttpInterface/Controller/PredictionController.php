<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\Controller;

use App\LanguageModel\Application\Command\GeneratePredictionCommand;
use App\LanguageModel\Application\Query\GetModelQuery;
use App\LanguageModel\Application\Query\GetPredictionQuery;
use App\LanguageModel\Application\Query\ListPredictionsQuery;
use App\LanguageModel\Domain\Inference\SamplingConfig;
use App\LanguageModel\Domain\Inference\SamplingStrategy;
use App\LanguageModel\Domain\Inference\PredictionId;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\HttpInterface\Form\GeneratePredictionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

// The "prediction" (text generation) pages: ask for a new
// generation, view a previous one.
final readonly class PredictionController
{
    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
    ) {
    }

    /**
     * Show the "generate" form (GET) and handle the submission
     // (POST). The form can be pre-filled with a model id from
     // the URL (e.g. /prediction/new?model=... comes from the
     // "Generate" button on a model page).
     */
    #[Route('/prediction/new', name: 'prediction_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->forms->create(GeneratePredictionType::class);
        $modelIdStr = (string) $request->query->get('model', '');
        if ($modelIdStr !== '') {
            $form->get('modelId')->setData($modelIdStr);
        }
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $modelId = ModelId::fromString($data['modelId']);
            $strategy = SamplingStrategy::from((string) $data['strategy']);
            // topK is only used for the TopK strategy; the form
            // sends a value regardless, so we ignore it for greedy.
            $sampling = $strategy === SamplingStrategy::TopK
                ? new SamplingConfig($strategy, (int) $data['maxNewTokens'], (int) $data['topK'])
                : new SamplingConfig($strategy, (int) $data['maxNewTokens']);
            $id = $this->commandBus->dispatch(new GeneratePredictionCommand(
                $modelId,
                (string) $data['prompt'],
                $sampling,
            ))->last(HandledStamp::class)->getResult();

            return new RedirectResponse('/prediction/'.$id);
        }

        return new Response($this->twig->render('prediction/new.html.twig', [
            'form' => $form->createView(),
        ]));
    }

    /**
     * Show a list of every prediction in the system.
     */
    #[Route('/prediction', name: 'prediction_list', methods: ['GET'])]
    public function list(): Response
    {
        $predictions = $this->queryBus->dispatch(new ListPredictionsQuery())->last(HandledStamp::class)->getResult();

        return new Response($this->twig->render('prediction/list.html.twig', [
            'predictions' => $predictions,
        ]));
    }

    /**
     * Show the status and (if finished) the text of one
     // prediction request.
     */
    #[Route('/prediction/{id}', name: 'prediction_show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(string $id): Response
    {
        $predictionId = PredictionId::fromString($id);
        $view = $this->queryBus->dispatch(new GetPredictionQuery($predictionId))->last(HandledStamp::class)->getResult();
        if ($view === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException("Prediction $id not found.");
        }

        return new Response($this->twig->render('prediction/show.html.twig', [
            'prediction' => $view,
        ]));
    }
}
