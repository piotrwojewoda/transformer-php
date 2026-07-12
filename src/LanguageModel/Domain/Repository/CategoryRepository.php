<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Repository;

use App\LanguageModel\Domain\Category\Category;
use App\LanguageModel\Domain\Category\CategoryId;

interface CategoryRepository
{
    public function save(Category $category): void;

    public function find(CategoryId $id): ?Category;

    /**
     * @return list<Category>
     */
    public function all(): array;
}
