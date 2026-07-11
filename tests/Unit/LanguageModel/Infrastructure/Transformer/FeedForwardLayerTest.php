<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Infrastructure\Transformer\FeedForwardLayer;
use App\LanguageModel\Infrastructure\Transformer\RandomGenerator;
use App\LanguageModel\Infrastructure\Transformer\Tensor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FeedForwardLayer::class)]
final class FeedForwardLayerTest extends TestCase
{
    public function testForward_isReLUOfLinear(): void
    {
        $layer = new FeedForwardLayer(2, 3);
        $p = $layer->initParams(new RandomGenerator(42));
        // Replace weights with deterministic values
        $p['w1'] = Tensor::fromMatrix([[1.0, 0.0, 1.0], [0.0, 1.0, 1.0]]);
        $p['b1'] = Tensor::zeros(3, 1);
        $p['w2'] = Tensor::fromMatrix([[1.0, 0.0], [0.0, 1.0], [0.0, 0.0]]);
        $p['b2'] = Tensor::zeros(2, 1);
        $layer->setLastWeights($p['w1'], $p['w2']);
        $x = Tensor::fromMatrix([[1.0, 1.0]]);
        $y = $layer->forward($x, $p);
        // h1 = ReLU(x @ W1) = [ReLU(1), ReLU(1), ReLU(2)] = [1, 1, 2]
        // y = h1 @ W2 = [1, 1]
        $this->assertEqualsWithDelta(1.0, $y->at(0, 0), 1e-9);
        $this->assertEqualsWithDelta(1.0, $y->at(0, 1), 1e-9);
    }

    public function testGradientMatchesFiniteDifference(): void
    {
        $dModel = 3;
        $dFf = 4;
        $layer = new FeedForwardLayer($dModel, $dFf);
        $p = $layer->initParams(new RandomGenerator(123));
        $layer->setLastWeights($p['w1'], $p['w2']);
        $x = Tensor::fromMatrix([[0.2, -0.1, 0.5]]);
        $layer->forward($x, $p);
        $dOut = Tensor::fromMatrix([[0.1, 0.2, 0.3]]);
        $analytical = $layer->backward($dOut);

        $eps = 1e-5;
        $fd = [];
        for ($i = 0; $i < $dModel; $i++) {
            $xp = $x->toMatrix();
            $xp[0][$i] += $eps;
            $xm = $x->toMatrix();
            $xm[0][$i] -= $eps;

            $layer2 = new FeedForwardLayer($dModel, $dFf);
            $layer2->setLastWeights($p['w1'], $p['w2']);
            $yp = $layer2->forward(Tensor::fromMatrix($xp), $p);
            $lp = 0.0;
            foreach ($dOut->data() as $k => $g) {
                $lp += $g * $yp->data()[$k];
            }

            $layer3 = new FeedForwardLayer($dModel, $dFf);
            $layer3->setLastWeights($p['w1'], $p['w2']);
            $ym = $layer3->forward(Tensor::fromMatrix($xm), $p);
            $lm = 0.0;
            foreach ($dOut->data() as $k => $g) {
                $lm += $g * $ym->data()[$k];
            }

            $fd[] = ($lp - $lm) / (2 * $eps);
        }
        for ($i = 0; $i < $dModel; $i++) {
            $this->assertEqualsWithDelta($fd[$i], $analytical->at(0, $i), 1e-3, "Mismatch at input dim $i");
        }
    }
}
