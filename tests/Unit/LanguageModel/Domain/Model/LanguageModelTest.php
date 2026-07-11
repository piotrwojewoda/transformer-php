<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Domain\Model;

use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\Weights;
use App\Shared\Infrastructure\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LanguageModel::class)]
final class LanguageModelTest extends TestCase
{
    public function testCreate_startsAsDraft(): void
    {
        $clock = new MockClock();
        $model = LanguageModel::create('m', new ModelConfig(8, 1, 1, 16, 32, 64), $clock);
        $this->assertSame('draft', $model->status()->value);
        $events = $model->pullDomainEvents();
        $this->assertCount(1, $events);
    }

    public function testMarkReady_transitionsFromDraft(): void
    {
        $clock = new MockClock();
        $model = LanguageModel::create('m', new ModelConfig(8, 1, 1, 16, 32, 64), $clock);
        $model->markReady($clock);
        $this->assertSame('ready', $model->status()->value);
    }

    public function testCannotTrainWhenStatusIsDraft(): void
    {
        $clock = new MockClock();
        $model = LanguageModel::create('m', new ModelConfig(8, 1, 1, 16, 32, 64), $clock);
        $this->expectException(\DomainException::class);
        $model->applyGradient(Weights::empty(), $clock);
    }

    public function testStateMachine_fullHappyPath(): void
    {
        $clock = new MockClock();
        $model = LanguageModel::create('m', new ModelConfig(8, 1, 1, 16, 32, 64), $clock);
        $model->markReady($clock);
        $model->startTraining($clock);
        $model->applyGradient(Weights::empty(), $clock);
        $model->markTrained(1, 0.5, $clock);
        $this->assertSame('trained', $model->status()->value);
    }

    public function testMarkFailed_canBeReachedFromAnyStatus(): void
    {
        $clock = new MockClock();
        $model = LanguageModel::create('m', new ModelConfig(8, 1, 1, 16, 32, 64), $clock);
        $model->markFailed('boom', $clock);
        $this->assertSame('failed', $model->status()->value);
    }

    public function testSetWeights_isRetrievable(): void
    {
        $clock = new MockClock();
        $model = LanguageModel::create('m', new ModelConfig(8, 1, 1, 16, 32, 64), $clock);
        $w = Weights::empty()->withUpdate('tokenEmbed', [[0.1, 0.2]]);
        $model->setWeights($w);
        $this->assertSame($w, $model->weights());
    }
}
