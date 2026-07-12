<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Corpus;

use App\LanguageModel\Domain\Event\TextIngested;
use App\LanguageModel\Domain\Token\Character;
use App\LanguageModel\Domain\Token\Vocabulary;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Clock;

// A "corpus" is just a bag of text that the model will learn from.
// Think of it as the textbook we'll use to teach the model.
//
// The Corpus aggregate root owns the raw text and knows how to
// describe its own character set. It is the source of truth for
// "what does the training data look like".
final class Corpus extends AggregateRoot
{
    public function __construct(
        public readonly CorpusId $id,
        public string $name,
        public string $rawText,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\App\LanguageModel\Domain\Category\CategoryId $categoryId = null,
    ) {
    }

    /**
     * Create a new corpus with the given name and text. Records a
     * TextIngested event so listeners (e.g. the vocabulary builder)
     * can react.
     */
    public static function create(string $name, string $text, Clock $clock, ?\App\LanguageModel\Domain\Category\CategoryId $categoryId = null): self
    {
        $corpus = new self(
            CorpusId::create(),
            $name,
            $text,
            $clock->now(),
            $categoryId,
        );
        $corpus->recordThat(new TextIngested($corpus->id, mb_strlen($text, 'UTF-8')));

        return $corpus;
    }

    /**
     * Add more text to the corpus. Records another TextIngested
     // event for the new chunk.
     */
    public function appendChunk(string $chunk): void
    {
        $this->rawText .= $chunk;
        $this->recordThat(new TextIngested($this->id, mb_strlen($chunk, 'UTF-8')));
    }

    /**
     * How many characters (codepoints) the corpus has.
     */
    public function length(): int
    {
        return mb_strlen($this->rawText, 'UTF-8');
    }

    /**
     * Walk through the whole text and collect every character that
     * appears, in the order it first appears. Duplicates are
     // skipped, so the result is the "alphabet" of the corpus.
     *
     * @return list<Character>
     */
    public function uniqueCharacters(): array
    {
        $seen = [];
        $out = [];
        for ($i = 0; $i < mb_strlen($this->rawText, 'UTF-8'); $i++) {
            $char = mb_substr($this->rawText, $i, 1, 'UTF-8');
            $c = Character::fromChar($char);
            if (isset($seen[$c->codepoint])) {
                continue;
            }
            $seen[$c->codepoint] = true;
            $out[] = $c;
        }

        return $out;
    }

    /**
     * Build a vocabulary for this corpus: a fresh vocabulary with
     // every unique character in the corpus added in order.
     */
    public function buildVocabulary(): Vocabulary
    {
        $vocab = Vocabulary::empty($this->id);
        foreach ($this->uniqueCharacters() as $c) {
            $vocab->addCharacter($c);
        }

        return $vocab;
    }
}
