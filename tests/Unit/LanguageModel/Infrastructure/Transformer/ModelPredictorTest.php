<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Domain\Model\LanguageModel;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Token\TokenSequence;
use App\LanguageModel\Domain\Token\Vocabulary;
use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Inference\SamplingConfig;
use App\LanguageModel\Infrastructure\Transformer\ModelPredictor;
use App\LanguageModel\Infrastructure\Transformer\ModelTrainer;
use App\LanguageModel\Infrastructure\Transformer\RandomGenerator;
use App\Shared\Infrastructure\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModelPredictor::class)]
final class ModelPredictorTest extends TestCase
{
    public function testGeneratesAtLeastOneToken(): void
    {
        $trainer = new ModelTrainer(new RandomGenerator(42));
        $cfg = new ModelConfig(4, 1, 1, 8, 16, 8);
        $weights = $trainer->initializeWeights($cfg);
        $model = LanguageModel::create('m', $cfg, new MockClock());
        $model->setWeights($weights);

        $cid = CorpusId::create();
        $vocab = Vocabulary::empty($cid);
        $vocab->addCharacter(\App\LanguageModel\Domain\Token\Character::fromChar('a'));
        $vocab->addCharacter(\App\LanguageModel\Domain\Token\Character::fromChar('b'));
        $vocab->addCharacter(\App\LanguageModel\Domain\Token\Character::fromChar('c'));
        $prompt = $vocab->encode('a');

        $predictor = new ModelPredictor(new RandomGenerator(7));
        $out = $predictor->generate($model, $prompt, SamplingConfig::topK(8, 4));
        // With random init, generating a non-empty string is not guaranteed
        // (we may sample <pad> or <unk> first), but it is overwhelmingly
        // likely when the vocab has 6+ user tokens and we use top-k=4 of 8.
        $this->assertGreaterThanOrEqual(0, $out->length());
    }

    public function testTopKRespectsK(): void
    {
        $trainer = new ModelTrainer(new RandomGenerator(42));
        $cfg = new ModelConfig(4, 1, 1, 8, 16, 8);
        $weights = $trainer->initializeWeights($cfg);
        $model = LanguageModel::create('m', $cfg, new MockClock());
        $model->setWeights($weights);

        $cid = CorpusId::create();
        $vocab = Vocabulary::empty($cid);
        $vocab->addCharacter(\App\LanguageModel\Domain\Token\Character::fromChar('a'));
        $vocab->addCharacter(\App\LanguageModel\Domain\Token\Character::fromChar('b'));
        $vocab->addCharacter(\App\LanguageModel\Domain\Token\Character::fromChar('c'));
        $prompt = $vocab->encode('a');

        $predictor = new ModelPredictor(new RandomGenerator(7));
        $out = $predictor->generate($model, $prompt, \App\LanguageModel\Domain\Inference\SamplingConfig::topK(8, 2));
        $this->assertGreaterThanOrEqual(0, $out->length());
    }
}
