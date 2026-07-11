<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\QueryHandler;

use App\LanguageModel\Application\Query\GetVocabQuery;
use App\LanguageModel\Domain\Repository\VocabularyRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// Handles the GetVocabQuery. Returns a list of every
// (token id, character) pair in the vocabulary, or an empty
// list if the corpus has no vocabulary.
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetVocabHandler
{
    public function __construct(private VocabularyRepository $vocabularies)
    {
    }

    /**
     * @return list<array{id: int, char: string}>
     */
    public function __invoke(GetVocabQuery $query): array
    {
        $vocab = $this->vocabularies->findByCorpus($query->corpusId);
        if ($vocab === null) {
            return [];
        }

        return $vocab->entries();
    }
}
