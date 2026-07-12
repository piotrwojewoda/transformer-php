<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// The "corpora" database row. Holds the raw training text
// plus identifying info.
class CorpusEntity
{
    public ?int $id = null;
    public string $uuid = '';
    public string $name = '';
    // The actual text. Could be big; we use LONGTEXT in the DB.
    public string $rawText = '';
    public ?string $categoryUuid = null;
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
