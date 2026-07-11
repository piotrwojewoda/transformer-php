<?php

declare(strict_types=1);

namespace App\LanguageModel\Application\Port;

use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Token\TokenSequence;
use App\LanguageModel\Domain\Token\Vocabulary;

// A "port" in hexagonal architecture is an interface the
// application layer uses to talk to the outside world. The
// concrete implementation lives in the Infrastructure layer
// (in our case: CharacterTokenizer). By depending on the
// interface, the rest of the code doesn't need to know HOW
// tokenization is done.
interface TokenizerPort
{
    /**
     * Read the text and produce a fresh Vocabulary that contains
     * every unique character.
     */
    public function buildVocabulary(CorpusId $corpusId, string $text): Vocabulary;

    /**
     * Turn text into a TokenSequence using the given vocabulary.
     */
    public function tokenize(Vocabulary $vocabulary, string $text): TokenSequence;

    /**
     * Turn a TokenSequence back into text.
     */
    public function detokenize(Vocabulary $vocabulary, TokenSequence $sequence): string;

    /**
     * Ensure the vocabulary contains all characters in $text;
     // return the (possibly extended) vocabulary and the
     // encoded sequence.
     *
     * @return array{Vocabulary, TokenSequence}
     */
    public function encodeWithVocabulary(Vocabulary $vocabulary, string $text): array;
}
