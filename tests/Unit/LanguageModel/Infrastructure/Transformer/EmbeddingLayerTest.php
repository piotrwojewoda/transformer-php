<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Infrastructure\Transformer\EmbeddingLayer;
use App\LanguageModel\Infrastructure\Transformer\RandomGenerator;
use App\LanguageModel\Infrastructure\Transformer\Tensor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingLayer::class)]
final class EmbeddingLayerTest extends TestCase
{
    public function testInitWeights_hasCorrectShape(): void
    {
        $layer = new EmbeddingLayer(10, 4);
        $w = $layer->initWeights(new RandomGenerator(42));
        $this->assertSame(10, $w->rows);
        $this->assertSame(4, $w->cols);
    }

    public function testForward_returnsRowsForGivenTokenIds(): void
    {
        $layer = new EmbeddingLayer(10, 4);
        $w = $layer->initWeights(new RandomGenerator(42));
        $out = $layer->forward([0, 1, 2], $w);
        $this->assertSame(3, $out->rows);
        $this->assertSame(4, $out->cols);
        // Each row should match the corresponding weight row
        $this->assertEqualsWithDelta($w->at(0, 0), $out->at(0, 0), 1e-9);
        $this->assertEqualsWithDelta($w->at(1, 1), $out->at(1, 1), 1e-9);
        $this->assertEqualsWithDelta($w->at(2, 3), $out->at(2, 3), 1e-9);
    }

    public function testBackwardScattersGradientsCorrectly(): void
    {
        $layer = new EmbeddingLayer(10, 4);
        $w = $layer->initWeights(new RandomGenerator(42));
        $layer->forward([0, 1, 0], $w);
        $dOut = Tensor::fromMatrix([
            [1.0, 2.0, 3.0, 4.0],
            [5.0, 6.0, 7.0, 8.0],
            [9.0, 10.0, 11.0, 12.0],
        ]);
        $layer->backward([0, 1, 0], $dOut);
        $grad = $layer->getWeightGrad();
        // Token 0 was used at positions 0 and 2; gradient should be dOut[0] + dOut[2].
        $this->assertEqualsWithDelta(1.0 + 9.0, $grad->at(0, 0), 1e-9);
        $this->assertEqualsWithDelta(2.0 + 10.0, $grad->at(0, 1), 1e-9);
        // Token 1 only at position 1.
        $this->assertEqualsWithDelta(5.0, $grad->at(1, 0), 1e-9);
    }
}
