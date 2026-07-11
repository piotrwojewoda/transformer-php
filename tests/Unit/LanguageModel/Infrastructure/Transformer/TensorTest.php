<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Infrastructure\Transformer\Tensor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tensor::class)]
final class TensorTest extends TestCase
{
    public function testZeros_hasCorrectShape(): void
    {
        $t = Tensor::zeros(2, 3);
        $this->assertSame(2, $t->rows);
        $this->assertSame(3, $t->cols);
        $this->assertCount(6, $t->data());
        $this->assertSame(0.0, $t->data()[0]);
    }

    public function testFromMatrix_storesRowMajorData(): void
    {
        $t = Tensor::fromMatrix([[1.0, 2.0], [3.0, 4.0]]);
        $this->assertSame(1.0, $t->at(0, 0));
        $this->assertSame(2.0, $t->at(0, 1));
        $this->assertSame(3.0, $t->at(1, 0));
        $this->assertSame(4.0, $t->at(1, 1));
    }

    public function testTranspose_swapsShapeAndContent(): void
    {
        $t = Tensor::fromMatrix([[1.0, 2.0], [3.0, 4.0]]);
        $tt = $t->transpose();
        $this->assertSame(2, $tt->rows);
        $this->assertSame(2, $tt->cols);
        $this->assertSame(1.0, $tt->at(0, 0));
        $this->assertSame(3.0, $tt->at(0, 1));
        $this->assertSame(2.0, $tt->at(1, 0));
        $this->assertSame(4.0, $tt->at(1, 1));
    }

    public function testAdd_returnsNewTensor(): void
    {
        $a = Tensor::fromMatrix([[1.0, 2.0]]);
        $b = Tensor::fromMatrix([[3.0, 4.0]]);
        $c = $a->add($b);
        $this->assertSame(4.0, $c->at(0, 0));
        $this->assertSame(6.0, $c->at(0, 1));
    }

    public function testMatmul_correctForSmallMatrices(): void
    {
        $a = Tensor::fromMatrix([[1.0, 2.0], [3.0, 4.0]]);
        $b = Tensor::fromMatrix([[5.0, 6.0], [7.0, 8.0]]);
        $c = $a->matmul($b);
        $this->assertSame(19.0, $c->at(0, 0));
        $this->assertSame(22.0, $c->at(0, 1));
        $this->assertSame(43.0, $c->at(1, 0));
        $this->assertSame(50.0, $c->at(1, 1));
    }

    public function testMatmul_rejectsShapeMismatch(): void
    {
        $a = Tensor::fromMatrix([[1.0, 2.0]]);
        $b = Tensor::fromMatrix([[3.0, 4.0, 5.0]]);
        $this->expectException(\InvalidArgumentException::class);
        $a->matmul($b);
    }

    public function testScale_multipliesAllElements(): void
    {
        $t = Tensor::fromMatrix([[1.0, 2.0], [3.0, 4.0]]);
        $s = $t->scale(2.0);
        $this->assertSame(2.0, $s->at(0, 0));
        $this->assertSame(8.0, $s->at(1, 1));
    }

    public function testApply_appliesCallable(): void
    {
        $t = Tensor::fromMatrix([[1.0, 2.0]]);
        $a = $t->apply(static fn (float $v) => $v * $v);
        $this->assertSame(1.0, $a->at(0, 0));
        $this->assertSame(4.0, $a->at(0, 1));
    }

    public function testSum_returnsTotal(): void
    {
        $t = Tensor::fromMatrix([[1.0, 2.0], [3.0, 4.0]]);
        $this->assertSame(10.0, $t->sum());
    }

    public function testEquals_treatsCloseFloatsAsEqual(): void
    {
        $a = Tensor::fromMatrix([[1.0, 2.0]]);
        $b = Tensor::fromMatrix([[1.0 + 1e-12, 2.0]]);
        $this->assertTrue($a->equals($b, 1e-9));
    }
}
