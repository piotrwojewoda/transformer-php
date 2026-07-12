<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Application\QueryHandler;

use App\LanguageModel\Application\Query\GetTrainingHistoryQuery;
use App\LanguageModel\Application\QueryHandler\GetTrainingHistoryHandler;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use App\LanguageModel\Domain\Training\TrainingConfig;
use App\LanguageModel\Domain\Training\TrainingJob;
use App\LanguageModel\Domain\Training\TrainingLoss;
use App\LanguageModel\Domain\Training\TrainingStatus;
use App\Shared\Domain\Clock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetTrainingHistoryHandler::class)]
final class GetTrainingHistoryHandlerTest extends TestCase
{
    private TrainingJobRepository $jobs;
    private Clock $clock;
    private GetTrainingHistoryHandler $handler;
    private ModelId $modelId;

    protected function setUp(): void
    {
        $this->jobs = $this->createMock(TrainingJobRepository::class);
        $this->clock = new \App\Shared\Infrastructure\Clock\MockClock(
            new \DateTimeImmutable('2025-01-01T00:10:00Z'),
        );
        $this->handler = new GetTrainingHistoryHandler($this->jobs, $this->clock);
        $this->modelId = ModelId::create();
    }

    public function testInvoke_returnsNull_whenNoJobs(): void
    {
        $this->jobs->expects($this->once())->method('findByModel')->willReturn([]);

        $result = ($this->handler)(new GetTrainingHistoryQuery($this->modelId));
        $this->assertNull($result);
    }

    public function testInvoke_returnsQueuedView_withoutElapsed(): void
    {
        $job = $this->makeQueuedJob();
        $this->jobs->expects($this->once())->method('findByModel')->willReturn([$job]);
        $this->jobs->expects($this->once())->method('lossHistory')->willReturn([]);

        $view = ($this->handler)(new GetTrainingHistoryQuery($this->modelId));

        $this->assertNotNull($view);
        $this->assertSame($job->status()->value, $view->status);
        $this->assertNull($view->startedAt);
        $this->assertNull($view->elapsedSeconds);
        $this->assertNull($view->estimatedRemainingSeconds);
        $this->assertTrue($view->isLive);
    }

    public function testInvoke_returnsRunningView_withElapsedAndEta(): void
    {
        $job = $this->makeRunningJob(2, 10);
        $this->jobs->expects($this->once())->method('findByModel')->willReturn([$job]);
        $this->jobs->expects($this->once())->method('lossHistory')->willReturn([
            ['epoch' => 0, 'loss' => 2.0],
            ['epoch' => 1, 'loss' => 1.5],
        ]);

        $view = ($this->handler)(new GetTrainingHistoryQuery($this->modelId));

        $this->assertNotNull($view);
        $this->assertSame('running', $view->status);
        $this->assertNotNull($view->startedAt);
        // startedAt is 2025-01-01T00:00:00, clock is 00:10:00 -> 600s elapsed
        $this->assertEqualsWithDelta(600.0, $view->elapsedSeconds, 0.01);
        // 2 epochs done, 8 remaining -> estimated = (600/2)*8 = 2400s
        $this->assertEqualsWithDelta(2400.0, $view->estimatedRemainingSeconds, 0.01);
        $this->assertTrue($view->isLive);
        $this->assertCount(2, $view->points);
    }

    public function testInvoke_returnsDoneView_notLive(): void
    {
        $job = $this->makeDoneJob(10, 10);
        $this->jobs->expects($this->once())->method('findByModel')->willReturn([$job]);
        $this->jobs->expects($this->once())->method('lossHistory')->willReturn([]);

        $view = ($this->handler)(new GetTrainingHistoryQuery($this->modelId));

        $this->assertNotNull($view);
        $this->assertSame('done', $view->status);
        $this->assertFalse($view->isLive);
    }

    private function makeQueuedJob(): TrainingJob
    {
        $job = TrainingJob::queue(
            $this->modelId,
            new TrainingConfig(0.005, 10, 32),
            new \App\Shared\Infrastructure\Clock\MockClock(new \DateTimeImmutable('2025-01-01T00:00:00Z')),
        );

        return $job;
    }

    private function makeRunningJob(int $epoch, int $totalEpochs): TrainingJob
    {
        $clock = new \App\Shared\Infrastructure\Clock\MockClock(new \DateTimeImmutable('2025-01-01T00:00:00Z'));
        $job = TrainingJob::queue($this->modelId, new TrainingConfig(0.005, $totalEpochs, 32), $clock);
        $job->start($clock);
        for ($i = 0; $i < $epoch; $i++) {
            $job->recordEpoch($i, new TrainingLoss(2.0 - $i * 0.5), $clock);
        }

        return $job;
    }

    private function makeDoneJob(int $epoch, int $totalEpochs): TrainingJob
    {
        $clock = new \App\Shared\Infrastructure\Clock\MockClock(new \DateTimeImmutable('2025-01-01T00:00:00Z'));
        $job = TrainingJob::queue($this->modelId, new TrainingConfig(0.005, $totalEpochs, 32), $clock);
        $job->start($clock);
        for ($i = 0; $i < $totalEpochs; $i++) {
            $job->recordEpoch($i, new TrainingLoss(2.0 - $i * 0.2), $clock);
        }
        $job->complete($clock);

        return $job;
    }
}
