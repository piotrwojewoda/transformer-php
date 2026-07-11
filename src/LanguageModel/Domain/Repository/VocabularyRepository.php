<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Repository;

use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Token\Vocabulary;

// The repository for Vocabulary aggregate roots. A vocabulary
// belongs to a specific corpus (we may have many corpora), so
// the only way to look one up is by its corpus id.
interface VocabularyRepository
{
    public function save(Vocabulary $vocabulary): void;

    public function findByCorpus(CorpusId $corpusId): ?Vocabulary;
}
