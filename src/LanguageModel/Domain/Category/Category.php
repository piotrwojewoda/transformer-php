<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Category;

// A category groups corpora together. When training a model you
// pick a category, and the model learns from all corpora inside
// that category (concatenated in order, newest first).
final class Category
{
    public function __construct(
        public readonly CategoryId $id,
        public string $name,
    ) {
        if (trim($this->name) === '') {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }
        if (mb_strlen($this->name, 'UTF-8') > 120) {
            throw new \InvalidArgumentException('Category name cannot exceed 120 characters.');
        }
    }

    public static function create(string $name): self
    {
        return new self(CategoryId::create(), $name);
    }

    public function rename(string $name): void
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }
        $this->name = $name;
    }
}
