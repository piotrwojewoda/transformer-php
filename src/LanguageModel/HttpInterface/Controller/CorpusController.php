<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\Controller;

use App\LanguageModel\Application\Command\IngestTextCommand;
use App\LanguageModel\Application\Query\GetVocabQuery;
use App\LanguageModel\Domain\Category\CategoryId;
use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Repository\CategoryRepository;
use App\LanguageModel\Domain\Repository\CorpusRepository;
use App\LanguageModel\HttpInterface\Form\IngestTextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

// The "corpus" pages: create a new corpus, view an existing one.
// The controller is small; it just builds forms, dispatches
// commands/queries, and renders Twig templates.
final readonly class CorpusController
{
    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
        private CorpusRepository $corpora,
        private CategoryRepository $categories,
    ) {
    }

    /**
     * Show the "ingest new corpus" form (GET) and handle its
     // submission (POST). On success we dispatch an
     // IngestTextCommand and redirect to the new corpus page.
     */
    #[Route('/corpus/new', name: 'corpus_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->forms->create(IngestTextType::class, null, [
            'categories' => $this->categories->all(),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $categoryId = !empty($data['categoryId']) ? CategoryId::fromString($data['categoryId']) : null;
            $id = $this->commandBus->dispatch(new IngestTextCommand(
                $data['name'],
                $data['text'],
                $categoryId,
            ))->last(HandledStamp::class)->getResult();

            return new RedirectResponse('/corpus/'.$id);
        }

        return new Response($this->twig->render('corpus/new.html.twig', [
            'form' => $form->createView(),
        ]));
    }

    /**
     * Show the list of every corpus in the system.
     */
    #[Route('/corpus', name: 'corpus_list', methods: ['GET'])]
    public function list(): Response
    {
        return new Response($this->twig->render('corpus/list.html.twig', [
            'corpora' => $this->corpora->all(),
        ]));
    }

    /**
     * Show one corpus and its vocabulary. The id must look like
     // a UUID (the regex requirement below).
     */
    #[Route('/corpus/{id}', name: 'corpus_show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(string $id): Response
    {
        $corpusId = CorpusId::fromString($id);
        $corpus = $this->corpora->find($corpusId);
        if ($corpus === null) {
            throw $this->createNotFound("Corpus $id not found.");
        }
        $vocab = $this->queryBus->dispatch(new GetVocabQuery($corpusId))->last(HandledStamp::class)->getResult();

        return new Response($this->twig->render('corpus/show.html.twig', [
            'corpus' => $corpus,
            'vocab' => $vocab,
        ]));
    }

    /**
     * Tiny helper to throw a 404. The framework's
     // NotFoundHttpException is what the error handler turns
     // into a real 404 page.
     */
    private function createNotFound(string $msg): \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
    {
        return new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException($msg);
    }
}
