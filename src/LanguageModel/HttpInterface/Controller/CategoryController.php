<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\Controller;

use App\LanguageModel\Application\Command\CreateCategoryCommand;
use App\LanguageModel\Domain\Repository\CategoryRepository;
use App\LanguageModel\HttpInterface\Form\CreateCategoryType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class CategoryController
{
    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private MessageBusInterface $commandBus,
        private CategoryRepository $categories,
    ) {
    }

    #[Route('/category', name: 'category_list', methods: ['GET'])]
    public function list(): Response
    {
        return new Response($this->twig->render('category/list.html.twig', [
            'categories' => $this->categories->all(),
        ]));
    }

    #[Route('/category/new', name: 'category_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->forms->create(CreateCategoryType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $this->commandBus->dispatch(new CreateCategoryCommand($data['name']))->last(HandledStamp::class);

            return new RedirectResponse('/category');
        }

        return new Response($this->twig->render('category/new.html.twig', [
            'form' => $form->createView(),
        ]));
    }
}
