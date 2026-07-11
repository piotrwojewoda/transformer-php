<?php

declare(strict_types=1);

namespace App\LanguageModel\Domain\Token;

use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Event\VocabularyExtended;
use App\Shared\Domain\AggregateRoot;

// The "vocabulary" is the model's dictionary. It maps every
// character in the training text to a unique integer (its "token
// id"). The model only ever sees these integers, never the raw
// characters.
//
// Like a phone book, it works in two directions:
//   - Character -> id  (used when turning text into numbers)
//   - id -> Character  (used when turning numbers back into text)
final class Vocabulary extends AggregateRoot
{
    // Three SPECIAL token ids are reserved at the start of the
    // vocabulary. They never represent real characters; they have
    // special meaning to the model.
    public const PAD_ID = 0;   // <pad>   - "no content, just padding"
    public const BOS_ID = 1;   // <bos>   - "beginning of sequence"
    public const UNK_ID = 2;   // <unk>   - "unknown character"
    // The first id available for actual corpus characters.
    public const FIRST_USER_ID = 3;

    // The actual special characters we use for the three special
    // tokens. They are non-printing control characters so they
    // never collide with real text.
    public const PAD_CHAR = "\x00";
    public const BOS_CHAR = "\x01";
    public const UNK_CHAR = "\x02";

    /** @var array<int, Character> token id -> Character (codepoint) */
    private array $idToChar;

    /** @var array<int, int> codepoint -> token id */
    private array $charToId;

    public function __construct(
        public readonly CorpusId $corpusId,
        array $idToChar,
        array $charToId,
        private int $nextId,
    ) {
        $this->idToChar = $idToChar;
        $this->charToId = $charToId;
    }

    /**
     * A brand-new vocabulary with only the three special tokens.
     * Real characters are added later with addCharacter().
     */
    public static function empty(CorpusId $corpusId): self
    {
        $idToChar = [
            self::PAD_ID => new Character(mb_ord(self::PAD_CHAR, 'UTF-8')),
            self::BOS_ID => new Character(mb_ord(self::BOS_CHAR, 'UTF-8')),
            self::UNK_ID => new Character(mb_ord(self::UNK_CHAR, 'UTF-8')),
        ];
        $charToId = [
            mb_ord(self::PAD_CHAR, 'UTF-8') => self::PAD_ID,
            mb_ord(self::BOS_CHAR, 'UTF-8') => self::BOS_ID,
            mb_ord(self::UNK_CHAR, 'UTF-8') => self::UNK_ID,
        ];

        return new self($corpusId, $idToChar, $charToId, self::FIRST_USER_ID);
    }

    public function size(): int
    {
        return \count($this->idToChar);
    }

    public function nextId(): int
    {
        return $this->nextId;
    }

    /**
     * True if this character is already in the vocabulary.
     */
    public function contains(Character $c): bool
    {
        return isset($this->charToId[$c->codepoint]);
    }

    /**
     * Look up the Character that lives at a given token id.
     * Throws if the id doesn't exist.
     */
    public function characterAt(int $tokenId): Character
    {
        if (!isset($this->idToChar[$tokenId])) {
            throw new \OutOfBoundsException("No Character for tokenId $tokenId.");
        }

        return $this->idToChar[$tokenId];
    }

    /**
     * Find the token id for a character. If the character isn't in
     * the vocabulary, we return the <unk> id instead of crashing.
     * This is what makes the model robust to unfamiliar characters.
     */
    public function tokenIdOf(Character $c): TokenId
    {
        if (!isset($this->charToId[$c->codepoint])) {
            return new TokenId(self::UNK_ID);
        }

        return new TokenId($this->charToId[$c->codepoint]);
    }

    /**
     * Add a new character to the vocabulary. If the character is
     * already there, we just return its existing id. Otherwise we
     * give it the next free id and record a domain event.
     */
    public function addCharacter(Character $c): TokenId
    {
        if (isset($this->charToId[$c->codepoint])) {
            return new TokenId($this->charToId[$c->codepoint]);
        }
        $newId = $this->nextId++;
        $this->idToChar[$newId] = $c;
        $this->charToId[$c->codepoint] = $newId;

        $this->recordThat(new VocabularyExtended($this->corpusId, $newId, $c->toChar()));

        return new TokenId($newId);
    }

    /**
     * Convert text into a TokenSequence: one token per Unicode
     * character. Unknown characters become the <unk> token.
     */
    public function encode(string $text): TokenSequence
    {
        $ids = [];
        for ($i = 0; $i < mb_strlen($text, 'UTF-8'); $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $c = Character::fromChar($char);
            $ids[] = $this->tokenIdOf($c);
        }

        return new TokenSequence($ids);
    }

    /**
     * Convert a TokenSequence back into text. <pad> tokens are
     * skipped (they are invisible fillers), and unknown tokens
     // become "?" so the user sees something.
     */
    public function decode(TokenSequence $sequence): string
    {
        $out = '';
        foreach ($sequence->ids as $id) {
            $tid = $id->value;
            if ($tid === self::PAD_ID) {
                continue;
            }
            if (!isset($this->idToChar[$tid])) {
                $out .= '?';

                continue;
            }
            $char = $this->idToChar[$tid];
            if ($tid === self::UNK_ID) {
                $out .= '?';

                continue;
            }
            $out .= $char->toChar();
        }

        return $out;
    }

    /**
     * List every (id, character) pair, sorted by id.
     * Used to show the user what the vocabulary contains.
     *
     * @return list<array{id: int, char: string}>
     */
    public function entries(): array
    {
        $out = [];
        foreach ($this->idToChar as $id => $char) {
            $out[] = ['id' => $id, 'char' => $char->toChar()];
        }
        ksort($out);

        return $out;
    }
}
