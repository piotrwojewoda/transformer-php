<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Application\CommandHandler;

use App\LanguageModel\Application\Command\CreateModelCommand;
use App\LanguageModel\Application\CommandHandler\CreateModelHandler;
use App\LanguageModel\Application\Port\TrainerPort;
use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Model\Weights;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Domain\Training\TrainingConfig;
use App\Shared\Domain\DomainEventCollector;
use App\Shared\Infrastructure\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateModelHandler::class)]
final class CreateModelHandlerTest extends TestCase
{
    public function testHandle_createsAndPersistsModelAndWeights(): void
    {
        $models = $this->createMock(LanguageModelRepository::class);
        $trainer = $this->createMock(TrainerPort::class);
        $events = new DomainEventCollector();
        $clock = new MockClock();

        $config = new ModelConfig(4, 1, 1, 8, 16, 8);
        $training = new TrainingConfig(0.005, 10, 16);
        $weights = Weights::empty();

        $trainer->expects($this->once())->method('initializeWeights')
            ->with($config)->willReturn($weights);

        $saved = null;
        $models->expects($this->once())->method('save')
            ->willReturnCallback(function (LanguageModel $m) use (&$saved) {
                $saved = $m;
            });
        $models->expects($this->once())->method('saveWeights')
            ->willReturnCallback(function (ModelId $id, Weights $w) use ($weights) {
                $this->assertSame($weights, $w);
            });

        $handler = new CreateModelHandler($models, $trainer, $clock, $events);
        $id = $handler(new CreateModelCommand('m', $config, $training));

        $this->assertInstanceOf(ModelId::class, $id);
        $this->assertNotNull($saved);
        $this->assertSame('ready', $saved->status()->value);
    }
}
