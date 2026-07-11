<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Token;

// An "interface" is a contract: it says "anything that calls itself
// a Tokenizer must provide these two methods". The actual class
// (CharacterTokenizer in our case) decides HOW to do it.
interface Tokenizer
{
    /**
     * Turn text into a TokenSequence using the given vocabulary.
     */
    public function encode(Vocabulary $vocab, string $text): TokenSequence;

    /**
     * Turn a TokenSequence back into text using the same vocabulary.
     */
    public function decode(Vocabulary $vocab, TokenSequence $sequence): string;
}
