<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Infrastructure\Transformer\RandomGenerator;
use App\LanguageModel\Infrastructure\Transformer\SoftmaxCrossEntropy;
use App\LanguageModel\Infrastructure\Transformer\Tensor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SoftmaxCrossEntropy::class)]
final class SoftmaxCrossEntropyTest extends TestCase
{
    public function testForward_isMeanNegativeLogProb(): void
    {
        $sce = new SoftmaxCrossEntropy();
        $logits = Tensor::fromMatrix([[0.0, 0.0]]);
        $loss = $sce->forward($logits, [0]);
        $this->assertEqualsWithDelta(\log(2.0), $loss, 1e-9);
    }

    public function testBackward_gradientIsScaledProbMinusOneHot(): void
    {
        $sce = new SoftmaxCrossEntropy();
        $logits = Tensor::fromMatrix([[0.0, 0.0]]);
        $sce->forward($logits, [0]);
        $grad = $sce->backward([0]);
        // softmax = [0.5, 0.5]; -onehot[0] = [-1, 0]; so grad = [0.5 - 1, 0.5] = [-0.5, 0.5]
        $this->assertEqualsWithDelta(-0.5, $grad->at(0, 0), 1e-9);
        $this->assertEqualsWithDelta(0.5, $grad->at(0, 1), 1e-9);
    }

    public function testGradientMatchesFiniteDifference(): void
    {
        $sce = new SoftmaxCrossEntropy();
        $logits = Tensor::fromMatrix([[0.1, 0.2, 0.3], [0.5, 0.1, 0.4]]);
        $targets = [2, 1];
        $sce->forward($logits, $targets);
        $analytical = $sce->backward($targets);
        $eps = 1e-5;
        for ($i = 0; $i < $logits->rows; $i++) {
            for ($j = 0; $j < $logits->cols; $j++) {
                $lp = $logits->toMatrix();
                $lp[$i][$j] += $eps;
                $lm = $logits->toMatrix();
                $lm[$i][$j] -= $eps;
                $fp = (new SoftmaxCrossEntropy())->forward(Tensor::fromMatrix($lp), $targets);
                $fm = (new SoftmaxCrossEntropy())->forward(Tensor::fromMatrix($lm), $targets);
                $fd = ($fp - $fm) / (2 * $eps);
                $this->assertEqualsWithDelta($fd, $analytical->at($i, $j), 1e-4, "Mismatch at ($i, $j)");
            }
        }
    }
}
