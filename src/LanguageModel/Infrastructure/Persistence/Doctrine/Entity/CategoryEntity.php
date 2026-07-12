<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

class CategoryEntity
{
    public ?int $id = null;
    public string $uuid = '';
    public string $name = '';
}
