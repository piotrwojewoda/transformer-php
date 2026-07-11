<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Infrastructure\Transformer\LayerNorm;
use App\LanguageModel\Infrastructure\Transformer\RandomGenerator;
use App\LanguageModel\Infrastructure\Transformer\Tensor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LayerNorm::class)]
final class LayerNormTest extends TestCase
{
    public function testForward_normalizesEachRow(): void
    {
        $layer = new LayerNorm(3);
        $params = $layer->initParams(new RandomGenerator(42));
        $x = Tensor::fromMatrix([[1.0, 2.0, 3.0]]);
        $y = $layer->forward($x, $params['gamma'], $params['beta']);
        // Per-row: mean=2, var=2/3, std=sqrt(2/3)
        // Normalized values: [(1-2)/s, 0/s, (3-2)/s] = [-sqrt(3/2), 0, sqrt(3/2)]
        // With gamma=1, beta=0, the output is exactly that.
        $s = \sqrt(3.0 / 2.0);
        $this->assertEqualsWithDelta(-$s, $y->at(0, 0), 1e-5);
        $this->assertEqualsWithDelta(0.0, $y->at(0, 1), 1e-5);
        $this->assertEqualsWithDelta($s, $y->at(0, 2), 1e-5);
    }

    public function testBackward_producesGradients(): void
    {
        $layer = new LayerNorm(2);
        $gamma = new Tensor(2, 1, [1.0, 1.0]);
        $beta = new Tensor(2, 1, [0.0, 0.0]);
        $x = Tensor::fromMatrix([[1.0, 3.0]]);
        $layer->forward($x, $gamma, $beta);
        $dOut = Tensor::fromMatrix([[1.0, 1.0]]);
        $dIn = $layer->backward($dOut);
        $this->assertSame(1, $dIn->rows);
        $this->assertSame(2, $dIn->cols);
        // dGamma sums dOut * xhat, dBeta sums dOut
        $gammaGrad = $layer->getGammaGrad();
        $betaGrad = $layer->getBetaGrad();
        $this->assertEqualsWithDelta(1.0, $betaGrad->at(0, 0), 1e-9);
        $this->assertEqualsWithDelta(1.0, $betaGrad->at(1, 0), 1e-9);
    }

    public function testGradientMatchesFiniteDifference(): void
    {
        $dModel = 4;
        $layer = new LayerNorm($dModel);
        $gamma = new Tensor($dModel, 1, [1.0, 1.0, 1.0, 1.0]);
        $beta = Tensor::zeros($dModel, 1);
        $x = Tensor::fromMatrix([[0.5, 1.5, -0.5, 2.0]]);
        $layer->forward($x, $gamma, $beta);
        $dOut = Tensor::fromMatrix([[0.1, -0.2, 0.3, 0.4]]);
        $analytical = $layer->backward($dOut);
        $eps = 1e-5;
        $fd = [];
        for ($i = 0; $i < $dModel; $i++) {
            $xp = $x->toMatrix();
            $xp[0][$i] += $eps;
            $xm = $x->toMatrix();
            $xm[0][$i] -= $eps;
            $fp = $this->scalarLoss($layer, Tensor::fromMatrix($xp), $gamma, $beta, $dOut);
            $fm = $this->scalarLoss($layer, Tensor::fromMatrix($xm), $gamma, $beta, $dOut);
            $fd[] = ($fp - $fm) / (2 * $eps);
        }
        for ($i = 0; $i < $dModel; $i++) {
            $this->assertEqualsWithDelta($fd[$i], $analytical->at(0, $i), 1e-4, "Mismatch at dim $i");
        }
    }

    private function scalarLoss(LayerNorm $layer, Tensor $x, Tensor $gamma, Tensor $beta, Tensor $dOut): float
    {
        $y = $layer->forward($x, $gamma, $beta);
        $sum = 0.0;
        foreach ($dOut->data() as $i => $g) {
            $sum += $g * $y->data()[$i];
        }
        $layer->reset();
        // Re-initialize state and forward again to keep the test idempotent
        $layer2 = new LayerNorm($gamma->rows);
        $layer2->forward($x, $gamma, $beta);
        unset($layer2);

        return $sum;
    }
}
