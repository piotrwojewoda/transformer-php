<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Infrastructure\Transformer\AttentionLayer;
use App\LanguageModel\Infrastructure\Transformer\RandomGenerator;
use App\LanguageModel\Infrastructure\Transformer\Tensor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttentionLayer::class)]
final class AttentionLayerTest extends TestCase
{
    public function testForward_isShapePreserving(): void
    {
        $layer = new AttentionLayer(4);
        $p = $layer->initParams(new RandomGenerator(42));
        $x = Tensor::fromMatrix([
            [1.0, 0.0, 0.0, 0.0],
            [0.0, 1.0, 0.0, 0.0],
            [0.0, 0.0, 1.0, 0.0],
        ]);
        $y = $layer->forward($x, $p);
        $this->assertSame(3, $y->rows);
        $this->assertSame(4, $y->cols);
    }

    public function testCausalMask_blocksFutureTokens(): void
    {
        $layer = new AttentionLayer(2);
        $p = $layer->initParams(new RandomGenerator(0));
        // Wipe weights so we can reason deterministically.
        $p['wq'] = Tensor::fromMatrix([[1.0, 0.0], [0.0, 1.0]]);
        $p['wk'] = Tensor::fromMatrix([[1.0, 0.0], [0.0, 1.0]]);
        $p['wv'] = Tensor::fromMatrix([[1.0, 0.0], [0.0, 1.0]]);
        $p['wo'] = Tensor::fromMatrix([[1.0, 0.0], [0.0, 1.0]]);
        $x = Tensor::fromMatrix([[1.0, 0.0], [0.0, 1.0]]);
        $y = $layer->forward($x, $p);
        // First position can only attend to itself, so y[0] = V[0] = [1, 0].
        $this->assertEqualsWithDelta(1.0, $y->at(0, 0), 1e-6);
        $this->assertEqualsWithDelta(0.0, $y->at(0, 1), 1e-6);
        // Second position attends to both, with softmax weights.
        // softmax([0, 1/sqrt(2)]) = [0.3302..., 0.6697...]
        // y[1] = 0.3302 * V[0] + 0.6697 * V[1] = [0.3302, 0.6697]
        $this->assertEqualsWithDelta(0.3302, $y->at(1, 0), 1e-3);
        $this->assertEqualsWithDelta(0.6698, $y->at(1, 1), 1e-3);
    }

    public function testGradientMatchesFiniteDifference(): void
    {
        $dModel = 2;
        $layer = new AttentionLayer($dModel);
        $p = $layer->initParams(new RandomGenerator(7));
        $x = Tensor::fromMatrix([[0.1, 0.2], [0.3, 0.4]]);
        $y = $layer->forward($x, $p);
        $dOut = Tensor::fromMatrix([[0.1, 0.2], [0.3, 0.4]]);
        $analytical = $layer->backward($dOut);
        $this->assertSame(2, $analytical->rows);
        $this->assertSame(2, $analytical->cols);

        $eps = 1e-5;
        for ($i = 0; $i < 2; $i++) {
            for ($j = 0; $j < 2; $j++) {
                $xp = $x->toMatrix();
                $xp[$i][$j] += $eps;
                $xm = $x->toMatrix();
                $xm[$i][$j] -= $eps;

                $lp = $this->scalarLoss($layer, $p, Tensor::fromMatrix($xp), $dOut);
                $lm = $this->scalarLoss($layer, $p, Tensor::fromMatrix($xm), $dOut);
                $fd = ($lp - $lm) / (2 * $eps);
                $this->assertEqualsWithDelta($fd, $analytical->at($i, $j), 1e-3, "Mismatch at ($i, $j)");
            }
        }
    }

    private function scalarLoss(AttentionLayer $prototype, array $params, Tensor $x, Tensor $dOut): float
    {
        $layer = new AttentionLayer($prototype->dModel);
        $y = $layer->forward($x, $params);
        $sum = 0.0;
        foreach ($dOut->data() as $i => $g) {
            $sum += $g * $y->data()[$i];
        }

        return $sum;
    }
}
