<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Domain\Model;

use App\LanguageModel\Domain\Model\Weights;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Weights::class)]
final class WeightsTest extends TestCase
{
    public function testEmpty_hasKnownShape(): void
    {
        $w = Weights::empty();
        $this->assertSame([], $w->get('tokenEmbed'));
        $this->assertSame([], $w->get('attn'));
    }

    public function testWithUpdate_modifiesDeepPath(): void
    {
        $w = Weights::empty()->withUpdate('attn.0.wq', [[1.0, 2.0], [3.0, 4.0]]);
        $this->assertSame([[1.0, 2.0], [3.0, 4.0]], $w->get('attn.0.wq'));
    }

    public function testGet_throwsOnMissingPath(): void
    {
        $w = Weights::empty();
        $this->expectException(\OutOfBoundsException::class);
        $w->get('attn.0.missing');
    }

    public function testEquals_comparesRecursively(): void
    {
        $a = Weights::empty()->withUpdate('tokenEmbed', [[0.1]]);
        $b = Weights::empty()->withUpdate('tokenEmbed', [[0.1]]);
        $c = Weights::empty()->withUpdate('tokenEmbed', [[0.2]]);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
