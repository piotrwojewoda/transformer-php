<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\CommandHandler;

use App\LanguageModel\Application\Command\CreateCategoryCommand;
use App\LanguageModel\Domain\Category\Category;
use App\LanguageModel\Domain\Category\CategoryId;
use App\LanguageModel\Domain\Repository\CategoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateCategoryHandler
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    public function __invoke(CreateCategoryCommand $command): CategoryId
    {
        $category = Category::create($command->name);
        $this->categories->save($category);

        return $category->id;
    }
}
