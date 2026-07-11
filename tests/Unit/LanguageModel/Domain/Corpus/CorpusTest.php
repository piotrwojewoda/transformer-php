<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Domain\Corpus;

use App\LanguageModel\Domain\Corpus\Corpus;
use App\Shared\Infrastructure\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Corpus::class)]
final class CorpusTest extends TestCase
{
    public function testCreate_raisesTextIngestedEvent(): void
    {
        $corpus = Corpus::create('test', 'hello world', new MockClock());
        $events = $corpus->pullDomainEvents();
        $this->assertCount(1, $events);
    }

    public function testAppendChunk_concatenatesAndRaisesEvent(): void
    {
        $corpus = Corpus::create('test', 'hello', new MockClock());
        $corpus->pullDomainEvents();
        $corpus->appendChunk(' world');
        $this->assertSame('hello world', $corpus->rawText);
        $this->assertCount(1, $corpus->pullDomainEvents());
    }

    public function testLength_isCharacterCount(): void
    {
        $corpus = Corpus::create('test', 'hello', new MockClock());
        $this->assertSame(5, $corpus->length());
    }

    public function testUniqueCharacters_returnsEachCharOnce(): void
    {
        $corpus = Corpus::create('test', 'hello', new MockClock());
        $chars = $corpus->uniqueCharacters();
        $this->assertCount(4, $chars);
    }

    public function testBuildVocabulary_containsAllUniqueChars(): void
    {
        $corpus = Corpus::create('test', 'hello', new MockClock());
        $vocab = $corpus->buildVocabulary();
        $this->assertSame(4 + 3, $vocab->size()); // 4 chars + 3 reserved
    }
}
