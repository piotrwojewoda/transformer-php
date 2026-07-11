<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Domain\Inference;

use App\LanguageModel\Domain\Inference\Prediction;
use App\LanguageModel\Domain\Inference\PredictionStatus;
use App\LanguageModel\Domain\Inference\SamplingConfig;
use App\LanguageModel\Domain\Inference\SamplingStrategy;
use App\LanguageModel\Domain\Model\ModelId;
use App\Shared\Infrastructure\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Prediction::class)]
#[CoversClass(SamplingConfig::class)]
#[CoversClass(SamplingStrategy::class)]
final class PredictionTest extends TestCase
{
    public function testQueue_startsAsQueued(): void
    {
        $p = Prediction::queue(ModelId::create(), 'hello', SamplingConfig::greedy(10), new MockClock());
        $this->assertSame(PredictionStatus::Queued, $p->status());
    }

    public function testComplete_marksAsDoneWithText(): void
    {
        $p = Prediction::queue(ModelId::create(), 'hello', SamplingConfig::greedy(10), new MockClock());
        $p->start();
        $p->complete('hello world', new MockClock());
        $this->assertSame('done', $p->status()->value);
        $this->assertSame('hello world', $p->generatedText());
    }

    public function testFail_recordsErrorAndTerminalStatus(): void
    {
        $p = Prediction::queue(ModelId::create(), 'hello', SamplingConfig::greedy(10), new MockClock());
        $p->start();
        $p->fail('boom', new MockClock());
        $this->assertSame('failed', $p->status()->value);
        $this->assertSame('boom', $p->errorMessage());
    }

    public function testComplete_rejectsWhenNotRunning(): void
    {
        $p = Prediction::queue(ModelId::create(), 'hello', SamplingConfig::greedy(10), new MockClock());
        $this->expectException(\DomainException::class);
        $p->complete('x', new MockClock());
    }

    public function testQueue_rejectsEmptyPrompt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Prediction::queue(ModelId::create(), '', SamplingConfig::greedy(10), new MockClock());
    }

    public function testSamplingConfig_topKRequiresK(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SamplingConfig(SamplingStrategy::TopK, 10);
    }

    public function testSamplingConfig_greedyRejectsTopK(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SamplingConfig(SamplingStrategy::Greedy, 10, 5);
    }

    public function testSamplingStrategy_isExhaustive(): void
    {
        $cases = SamplingStrategy::cases();
        $this->assertGreaterThanOrEqual(2, \count($cases));
        $this->assertContains(SamplingStrategy::Greedy, $cases);
        $this->assertContains(SamplingStrategy::TopK, $cases);
    }
}
