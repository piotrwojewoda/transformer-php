<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\CommandHandler;

use App\LanguageModel\Application\Command\IngestTextCommand;
use App\LanguageModel\Application\Port\TokenizerPort;
use App\LanguageModel\Domain\Corpus\Corpus;
use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Repository\CorpusRepository;
use App\LanguageModel\Domain\Repository\VocabularyRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventCollector;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// Handles the IngestTextCommand: takes some user text, creates a
// Corpus, builds a Vocabulary for it, saves both, and reports
// the new corpus id.
#[AsMessageHandler]
final readonly class IngestTextHandler
{
    public function __construct(
        private CorpusRepository $corpora,
        private VocabularyRepository $vocabularies,
        private TokenizerPort $tokenizer,
        private Clock $clock,
        private DomainEventCollector $events,
    ) {
    }

    public function __invoke(IngestTextCommand $command): CorpusId
    {
        // 1. Create the corpus aggregate. This records a
        //    TextIngested event.
        $corpus = Corpus::create($command->name, $command->text, $this->clock, $command->categoryId);
        $this->corpora->save($corpus);

        // 2. Build a vocabulary by walking through every unique
        //    character in the text. This records a
        //    VocabularyExtended event for each new character.
        $vocabulary = $this->tokenizer->buildVocabulary($corpus->id, $command->text);
        $this->vocabularies->save($vocabulary);

        // 3. Forward the corpus's events to the collector.
        $this->events->recordAll($corpus->pullDomainEvents());

        return $corpus->id;
    }
}
