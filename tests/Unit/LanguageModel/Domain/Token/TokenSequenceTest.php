<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Domain\Token;

use App\LanguageModel\Domain\Token\Character;
use App\LanguageModel\Domain\Token\TokenId;
use App\LanguageModel\Domain\Token\TokenSequence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenSequence::class)]
#[CoversClass(TokenId::class)]
final class TokenSequenceTest extends TestCase
{
    public function testLength_returnsCountOfIds(): void
    {
        $seq = new TokenSequence([new TokenId(0), new TokenId(1), new TokenId(2)]);
        $this->assertSame(3, $seq->length());
        $this->assertFalse($seq->isEmpty());
    }

    public function testEmpty_hasZeroLength(): void
    {
        $seq = TokenSequence::empty();
        $this->assertTrue($seq->isEmpty());
        $this->assertSame(0, $seq->length());
    }

    public function testAt_returnsCorrectId(): void
    {
        $seq = new TokenSequence([new TokenId(0), new TokenId(1), new TokenId(2)]);
        $this->assertSame(1, $seq->at(1)->value);
    }

    public function testWindow_returnsSlice(): void
    {
        $seq = new TokenSequence([new TokenId(0), new TokenId(1), new TokenId(2), new TokenId(3)]);
        $window = $seq->window(1, 3);
        $this->assertSame(2, $window->length());
        $this->assertSame(1, $window->at(0)->value);
        $this->assertSame(2, $window->at(1)->value);
    }

    public function testAppend_returnsNewSequenceWithId(): void
    {
        $seq = new TokenSequence([new TokenId(0)]);
        $appended = $seq->append(new TokenId(1));
        $this->assertSame(1, $seq->length());
        $this->assertSame(2, $appended->length());
        $this->assertSame(1, $appended->at(1)->value);
    }

    public function testEquals_worksForSameContent(): void
    {
        $a = new TokenSequence([new TokenId(0), new TokenId(1)]);
        $b = new TokenSequence([new TokenId(0), new TokenId(1)]);
        $c = new TokenSequence([new TokenId(1), new TokenId(0)]);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testCharacter_codepointRoundTrip(): void
    {
        $c = Character::fromChar('Z');
        $this->assertSame('Z', $c->toChar());
        $this->assertSame(\mb_ord('Z', 'UTF-8'), $c->codepoint);
    }
}
