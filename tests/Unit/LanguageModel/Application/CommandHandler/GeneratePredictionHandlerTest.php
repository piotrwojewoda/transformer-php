<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Application\CommandHandler;

use App\LanguageModel\Application\Command\GeneratePredictionCommand;
use App\LanguageModel\Application\CommandHandler\GeneratePredictionHandler;
use App\LanguageModel\Domain\Inference\PredictionId;
use App\LanguageModel\Domain\Inference\SamplingConfig;
use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Repository\LanguageModelRepository;
use App\LanguageModel\Domain\Repository\PredictionRepository;
use App\LanguageModel\Infrastructure\Messenger\Message\GeneratePredictionMessage;
use App\Shared\Infrastructure\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(GeneratePredictionHandler::class)]
final class GeneratePredictionHandlerTest extends TestCase
{
    public function testHandle_savesAndDispatches(): void
    {
        $models = $this->createMock(LanguageModelRepository::class);
        $predictions = $this->createMock(PredictionRepository::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $clock = new MockClock();

        $model = LanguageModel::create('m', new ModelConfig(4, 1, 1, 8, 16, 8), $clock);
        $model->markReady($clock);
        $models->expects($this->once())->method('find')->willReturn($model);
        $predictions->expects($this->once())->method('save');
        $bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(function ($m) {
                $this->assertInstanceOf(GeneratePredictionMessage::class, $m);

                return new Envelope($m);
            });

        $handler = new GeneratePredictionHandler($models, $predictions, $clock, $bus);
        $id = $handler(new GeneratePredictionCommand($model->id, 'hello', SamplingConfig::greedy(10)));
        $this->assertInstanceOf(PredictionId::class, $id);
    }

    public function testHandle_rejectsWhenModelInDraft(): void
    {
        $models = $this->createMock(LanguageModelRepository::class);
        $predictions = $this->createMock(PredictionRepository::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $clock = new MockClock();

        $model = LanguageModel::create('m', new ModelConfig(4, 1, 1, 8, 16, 8), $clock);
        $models->expects($this->once())->method('find')->willReturn($model);

        $handler = new GeneratePredictionHandler($models, $predictions, $clock, $bus);
        $this->expectException(\DomainException::class);
        $handler(new GeneratePredictionCommand($model->id, 'hello', SamplingConfig::greedy(10)));
    }
}
