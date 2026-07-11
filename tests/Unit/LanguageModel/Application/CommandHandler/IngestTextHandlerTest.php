<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Application\CommandHandler;

use App\LanguageModel\Application\Command\IngestTextCommand;
use App\LanguageModel\Application\CommandHandler\IngestTextHandler;
use App\LanguageModel\Application\Port\TokenizerPort;
use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Repository\CorpusRepository;
use App\LanguageModel\Domain\Repository\VocabularyRepository;
use App\LanguageModel\Domain\Token\Vocabulary;
use App\LanguageModel\Domain\Event\TextIngested;
use App\Shared\Domain\DomainEventCollector;
use App\Shared\Infrastructure\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IngestTextHandler::class)]
final class IngestTextHandlerTest extends TestCase
{
    public function testHandle_persistsCorpusAndVocabAndCollectsEvents(): void
    {
        $corpora = $this->createMock(CorpusRepository::class);
        $vocabularies = $this->createMock(VocabularyRepository::class);
        $tokenizer = $this->createMock(TokenizerPort::class);
        $events = new DomainEventCollector();
        $clock = new MockClock();

        $corpora->expects($this->once())->method('save');
        $vocabularies->expects($this->once())->method('save');

        $tokenizer->expects($this->once())->method('buildVocabulary')
            ->willReturnCallback(fn (CorpusId $id, string $text) => Vocabulary::empty($id));

        $handler = new IngestTextHandler($corpora, $vocabularies, $tokenizer, $clock, $events);
        $id = $handler(new IngestTextCommand('pangram', 'The quick brown fox.'));

        $this->assertInstanceOf(CorpusId::class, $id);
        $this->assertGreaterThan(0, $events->count());
        $pulled = $events->pull();
        $this->assertInstanceOf(TextIngested::class, $pulled[0]);
    }
}
