<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity;

// One row per (corpus, token id, character) triple. The same
// character can appear in different corpora with different
// token ids, so we link to a specific corpus.
class VocabularyEntity
{
    public ?int $id = null;
    public int $corpusId = 0;
    public int $tokenId = 0;
    // The actual character bytes. We store as raw bytes (VARBINARY)
    // because some Unicode characters are multi-byte and we
    // want to keep them exactly as-is.
    public string $character = '';
}
