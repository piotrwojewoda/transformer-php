<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Command;

// "Please create a new category with this name". Categories are
// used to group corpora; when training you pick a category and
// the model learns from all corpora inside it.
final readonly class CreateCategoryCommand
{
    public function __construct(public string $name)
    {
    }
}
