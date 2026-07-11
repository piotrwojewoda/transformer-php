<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Domain\Token;

use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Token\Character;
use App\LanguageModel\Domain\Token\TokenId;
use App\LanguageModel\Domain\Token\TokenSequence;
use App\LanguageModel\Domain\Token\Vocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Vocabulary::class)]
#[CoversClass(Character::class)]
#[CoversClass(TokenId::class)]
#[CoversClass(TokenSequence::class)]
final class VocabularyTest extends TestCase
{
    public function testReservedTokensAreImmutable(): void
    {
        $vocab = Vocabulary::empty(CorpusId::create());
        $this->assertSame(3, $vocab->size()); // 3 reserved
        $this->assertSame(0, Vocabulary::PAD_ID);
        $this->assertSame(1, Vocabulary::BOS_ID);
        $this->assertSame(2, Vocabulary::UNK_ID);
        $this->assertSame(3, $vocab->nextId());
    }

    public function testAddCharacter_returnsIncreasingIds(): void
    {
        $vocab = Vocabulary::empty(CorpusId::create());
        $a = $vocab->addCharacter(Character::fromChar('a'));
        $b = $vocab->addCharacter(Character::fromChar('b'));
        $this->assertSame(3, $a->value);
        $this->assertSame(4, $b->value);
    }

    public function testAddCharacter_isIdempotent(): void
    {
        $vocab = Vocabulary::empty(CorpusId::create());
        $a1 = $vocab->addCharacter(Character::fromChar('a'));
        $a2 = $vocab->addCharacter(Character::fromChar('a'));
        $this->assertTrue($a1->equals($a2));
        $this->assertSame(4, $vocab->nextId());
    }

    public function testEncode_mapsUnknownToUnk(): void
    {
        $vocab = Vocabulary::empty(CorpusId::create());
        $vocab->addCharacter(Character::fromChar('a'));
        $seq = $vocab->encode('az');
        $this->assertSame(3, $seq->ids[0]->value);
        $this->assertSame(2, $seq->ids[1]->value);
    }

    public function testDecode_padIsEmpty(): void
    {
        $vocab = Vocabulary::empty(CorpusId::create());
        $seq = new TokenSequence([new TokenId(0)]);
        $this->assertSame('', $vocab->decode($seq));
    }

    public function testRoundTrip_whenAllCharsKnown(): void
    {
        $vocab = Vocabulary::empty(CorpusId::create());
        foreach (\mb_str_split('hello') as $c) {
            $vocab->addCharacter(Character::fromChar($c));
        }
        $seq = $vocab->encode('hello');
        $this->assertSame('hello', $vocab->decode($seq));
    }

    public function testContains_afterAdd_returnsTrue(): void
    {
        $vocab = Vocabulary::empty(CorpusId::create());
        $vocab->addCharacter(Character::fromChar('a'));
        $this->assertTrue($vocab->contains(Character::fromChar('a')));
        $this->assertFalse($vocab->contains(Character::fromChar('z')));
    }
}
