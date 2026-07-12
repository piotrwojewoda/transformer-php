<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Application\CommandHandler;

use App\LanguageModel\Application\Command\TrainModelCommand;
use App\LanguageModel\Application\CommandHandler\TrainModelHandler;
use App\LanguageModel\Domain\Category\CategoryId;
use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\ModelId;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Domain\Repository\TrainingJobRepository;
use App\LanguageModel\Domain\Training\TrainingConfig;
use App\LanguageModel\Domain\Training\TrainingJobId;
use App\LanguageModel\Infrastructure\Messenger\Message\TrainModelMessage;
use App\Shared\Domain\DomainEventCollector;
use App\Shared\Infrastructure\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(TrainModelHandler::class)]
final class TrainModelHandlerTest extends TestCase
{
    public function testHandle_throwsWhenModelNotFound(): void
    {
        $models = $this->createMock(LanguageModelRepository::class);
        $jobs = $this->createMock(TrainingJobRepository::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $events = new DomainEventCollector();
        $clock = new MockClock();

        $models->expects($this->once())->method('find')->willReturn(null);
        $jobs->expects($this->never())->method('save');
        $bus->expects($this->never())->method('dispatch');

        $handler = new TrainModelHandler($models, $jobs, $clock, $events, $bus);
        $this->expectException(\RuntimeException::class);
        $handler(new TrainModelCommand(ModelId::create(), new TrainingConfig(0.005, 10, 32), CategoryId::create()));
    }

    public function testHandle_createsJobAndDispatchesMessage(): void
    {
        $models = $this->createMock(LanguageModelRepository::class);
        $jobs = $this->createMock(TrainingJobRepository::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $events = new DomainEventCollector();
        $clock = new MockClock();

        $cfg = new ModelConfig(4, 1, 1, 8, 16, 8);
        $model = LanguageModel::create('m', $cfg, $clock);
        $model->markReady($clock);
        $models->expects($this->once())->method('find')->willReturn($model);
        $jobs->expects($this->once())->method('save');
        $bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(function ($message) {
                $this->assertInstanceOf(TrainModelMessage::class, $message);

                return new Envelope($message);
            });

        $handler = new TrainModelHandler($models, $jobs, $clock, $events, $bus);
        $id = $handler(new TrainModelCommand($model->id, new TrainingConfig(0.005, 10, 32), CategoryId::create()));
        $this->assertInstanceOf(TrainingJobId::class, $id);
    }
}
