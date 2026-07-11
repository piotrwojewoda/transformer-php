<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Tokenizer;

use App\LanguageModel\Application\Port\TokenizerPort;
use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Token\Character;
use App\LanguageModel\Domain\Token\TokenSequence;
use App\LanguageModel\Domain\Token\Vocabulary;

// WHAT IS A TOKENIZER?
// -------------------
// A neural network can't read characters directly. It needs numbers.
// A "tokenizer" is the bridge: it chops text into pieces ("tokens")
// and turns each piece into an integer id. The simplest tokenizer is
// "one character = one token", which is what this class does.
final class CharacterTokenizer implements TokenizerPort
{
    /**
     * Build a vocabulary from a text by walking through it and
     * remembering every unique character we see.
     *
     * WHY? The vocabulary is the model's "alphabet". Once we know
     * what characters exist in the corpus, we can give each one a
     * unique number (its token id) and use that number everywhere.
     */
    public function buildVocabulary(CorpusId $corpusId, string $text): Vocabulary
    {
        // Start with an empty vocabulary (it already knows the special
        // <pad>, <bos>, and <unk> tokens; see Vocabulary::empty()).
        $vocab = Vocabulary::empty($corpusId);
        // $seen remembers which characters we've already added so we
        // don't add the same one twice.
        $seen = [];
        // mb_strlen counts Unicode codepoints, NOT bytes. So "é" is
        // one character, not two.
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            // Take one character out of the text.
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $c = Character::fromChar($char);
            if (isset($seen[$c->codepoint])) {
                continue;
            }
            $seen[$c->codepoint] = true;
            // Add the character to the vocabulary, which assigns it
            // a fresh id.
            $vocab->addCharacter($c);
        }

        return $vocab;
    }

    public function tokenize(Vocabulary $vocabulary, string $text): TokenSequence
    {
        return $vocabulary->encode($text);
    }

    public function detokenize(Vocabulary $vocabulary, TokenSequence $sequence): string
    {
        return $vocabulary->decode($sequence);
    }

    public function encodeWithVocabulary(Vocabulary $vocabulary, string $text): array
    {
        // Character-level: every codepoint is its own token, so no
        // growth happens. We just return the same vocabulary plus
        // the encoded sequence.
        return [$vocabulary, $vocabulary->encode($text)];
    }
}
