<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// A "Doctrine entity" is a PHP class that maps to a database
// table. Each public property corresponds to a column. We
// separate entities from the domain objects so the database
// schema can change without touching the business rules.
//
// This entity is the "header" row for a model: the metadata
// (name, config, status). The actual weight numbers live in
// their own tables (model_token_embeddings, etc.) and are
// connected by the integer $id.
class LanguageModelEntity
{
    // The auto-incremented primary key. null until the row is
    // first inserted.
    public ?int $id = null;
    // The public UUID we show in URLs and use across the
    // application. Different from the numeric id.
    public string $uuid = '';
    public string $name = '';
    public int $dModel = 0;
    public int $numHeads = 0;
    public int $numLayers = 0;
    public int $dFf = 0;
    public int $maxSeqLen = 0;
    public int $vocabSize = 0;
    // Status is stored as a string ("draft", "ready", etc.)
    // instead of an integer, so it's human-readable in the DB.
    public string $status = 'draft';
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        // Default to "now" when we first create the entity in
        // memory. Doctrine will overwrite these on insert.
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
}
