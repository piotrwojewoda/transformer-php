<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Domain\Training;

use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Training\TrainingConfig;
use App\LanguageModel\Domain\Training\TrainingJob;
use App\LanguageModel\Domain\Training\TrainingLoss;
use App\Shared\Infrastructure\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrainingJob::class)]
#[CoversClass(TrainingConfig::class)]
#[CoversClass(TrainingLoss::class)]
final class TrainingJobTest extends TestCase
{
    public function testQueue_startsAsQueued(): void
    {
        $job = TrainingJob::queue(ModelId::create(), new TrainingConfig(0.01, 10, 32), new MockClock());
        $this->assertSame('queued', $job->status()->value);
        $this->assertSame(0, $job->epoch());
    }

    public function testStart_transitionsToRunning(): void
    {
        $job = TrainingJob::queue(ModelId::create(), new TrainingConfig(0.01, 10, 32), new MockClock());
        $job->start(new MockClock());
        $this->assertSame('running', $job->status()->value);
    }

    public function testRecordEpoch_advancesCounter(): void
    {
        $job = TrainingJob::queue(ModelId::create(), new TrainingConfig(0.01, 3, 32), new MockClock());
        $job->start(new MockClock());
        $job->recordEpoch(0, new TrainingLoss(1.0), new MockClock());
        $job->recordEpoch(1, new TrainingLoss(0.5), new MockClock());
        $this->assertSame(2, $job->epoch());
        $this->assertNotNull($job->lastLoss());
    }

    public function testRecordEpoch_rejectsWrongSequence(): void
    {
        $job = TrainingJob::queue(ModelId::create(), new TrainingConfig(0.01, 3, 32), new MockClock());
        $job->start(new MockClock());
        $this->expectException(\DomainException::class);
        $job->recordEpoch(5, new TrainingLoss(1.0), new MockClock());
    }

    public function testComplete_transitionsToDone(): void
    {
        $job = TrainingJob::queue(ModelId::create(), new TrainingConfig(0.01, 2, 32), new MockClock());
        $job->start(new MockClock());
        $job->recordEpoch(0, new TrainingLoss(1.0), new MockClock());
        $job->recordEpoch(1, new TrainingLoss(0.5), new MockClock());
        $job->complete(new MockClock());
        $this->assertSame('done', $job->status()->value);
    }

    public function testComplete_rejectsIncompleteJob(): void
    {
        $job = TrainingJob::queue(ModelId::create(), new TrainingConfig(0.01, 5, 32), new MockClock());
        $job->start(new MockClock());
        $this->expectException(\DomainException::class);
        $job->complete(new MockClock());
    }

    public function testFail_recordsErrorAndTerminalStatus(): void
    {
        $job = TrainingJob::queue(ModelId::create(), new TrainingConfig(0.01, 5, 32), new MockClock());
        $job->start(new MockClock());
        $job->fail('boom', new MockClock());
        $this->assertSame('failed', $job->status()->value);
        $this->assertSame('boom', $job->errorMessage());
    }

    public function testTrainingConfig_rejectsNonPositiveValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrainingConfig(0.0, 10, 32);
    }

    public function testTrainingLoss_rejectsNegativeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrainingLoss(-1.0);
    }
}
