<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\Weights;
use App\LanguageModel\Domain\Token\TokenSequence;
use App\LanguageModel\Domain\Token\Vocabulary;
use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Training\TrainingConfig;
use App\LanguageModel\Infrastructure\Transformer\ModelTrainer;
use App\LanguageModel\Infrastructure\Transformer\RandomGenerator;
use App\Shared\Infrastructure\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModelTrainer::class)]
final class ModelTrainerTest extends TestCase
{
    public function testInitializeWeights_hasExpectedShape(): void
    {
        $trainer = new ModelTrainer(new RandomGenerator(42));
        $cfg = new ModelConfig(4, 1, 1, 8, 16, 8);
        $w = $trainer->initializeWeights($cfg);
        $this->assertSame(8, \count($w->get('tokenEmbed')));
        $this->assertSame(16, \count($w->get('posEmbed')));
        $this->assertCount(1, $w->get('attn'));
        $this->assertCount(1, $w->get('ffn'));
    }

    public function testLossDecreasesOverEpochsOnTinyCorpus(): void
    {
        $trainer = new ModelTrainer(new RandomGenerator(42));
        $cfg = new ModelConfig(4, 1, 1, 8, 16, 8);
        $weights = $trainer->initializeWeights($cfg);
        $cid = CorpusId::create();
        $vocab = Vocabulary::empty($cid);
        $vocab->addCharacter(\App\LanguageModel\Domain\Token\Character::fromChar('a'));
        $vocab->addCharacter(\App\LanguageModel\Domain\Token\Character::fromChar('b'));
        $vocab->addCharacter(\App\LanguageModel\Domain\Token\Character::fromChar('c'));
        $corpus = 'abcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabc';
        $tokenized = $vocab->encode($corpus);
        $this->assertGreaterThan(10, $tokenized->length());

        $model = LanguageModel::create('test', $cfg, new MockClock());
        $model->setWeights($weights);
        $model->markReady(new MockClock());

        $config = new TrainingConfig(0.05, 30, 16);
        $firstLoss = null;
        $lastLoss = null;
        for ($i = 0; $i < 30; $i++) {
            $weights = $trainer->trainOneEpoch($model, $tokenized, $config);
            $model->setWeights($weights);
            $loss = $trainer->lastLoss?->value ?? 0.0;
            if ($firstLoss === null) {
                $firstLoss = $loss;
            }
            $lastLoss = $loss;
        }

        $this->assertNotNull($firstLoss);
        $this->assertNotNull($lastLoss);
        $this->assertLessThan($firstLoss * 0.95, $lastLoss, "Loss did not decrease: $firstLoss -> $lastLoss");
    }
}
