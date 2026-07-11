<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Query;

use App\LanguageModel\Domain\Corpus\CorpusId;

// "Please give me the vocabulary for this corpus".
final readonly class GetVocabQuery
{
    public function __construct(public CorpusId $corpusId)
    {
    }
}
