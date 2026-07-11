<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Infrastructure\Tokenizer;

use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Token\TokenSequence;
use App\LanguageModel\Infrastructure\Tokenizer\CharacterTokenizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterTokenizer::class)]
final class CharacterTokenizerTest extends TestCase
{
    public function testRoundTrip_preservesOriginalString(): void
    {
        $t = new CharacterTokenizer();
        $cid = CorpusId::create();
        $v = $t->buildVocabulary($cid, 'hello world');
        $seq = $t->tokenize($v, 'hello world');
        $this->assertSame('hello world', $t->detokenize($v, $seq));
    }

    public function testUnknownCharMapsToUnk(): void
    {
        $t = new CharacterTokenizer();
        $cid = CorpusId::create();
        $v = $t->buildVocabulary($cid, 'abc');
        $seq = $t->tokenize($v, 'xyz');
        foreach ($seq->ids as $id) {
            $this->assertSame(2, $id->value);
        }
    }

    public function testBuildVocabulary_assignsExpectedSize(): void
    {
        $t = new CharacterTokenizer();
        $cid = CorpusId::create();
        $v = $t->buildVocabulary($cid, 'abcabc');
        $this->assertSame(3 + 3, $v->size()); // 3 chars + 3 reserved
    }

    public function testTokenize_emptyString_returnsEmpty(): void
    {
        $t = new CharacterTokenizer();
        $cid = CorpusId::create();
        $v = $t->buildVocabulary($cid, 'a');
        $seq = $t->tokenize($v, '');
        $this->assertInstanceOf(TokenSequence::class, $seq);
        $this->assertSame(0, $seq->length());
    }
}
