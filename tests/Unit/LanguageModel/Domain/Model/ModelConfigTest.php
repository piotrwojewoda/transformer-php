<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Domain\Model;

use App\LanguageModel\Domain\Model\ModelConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModelConfig::class)]
final class ModelConfigTest extends TestCase
{
    public function testConstruct_acceptsValidConfig(): void
    {
        $config = new ModelConfig(8, 1, 1, 16, 32, 64);
        $this->assertSame(8, $config->dModel);
        $this->assertSame(8, $config->dHead());
    }

    public function testConstruct_rejectsIndivisibleDModelByHeads(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModelConfig(8, 3, 1, 16, 32, 64);
    }

    public function testConstruct_rejectsNonPositiveDims(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModelConfig(0, 1, 1, 16, 32, 64);
    }

    public function testConstruct_rejectsVocabSizeLessThan4(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModelConfig(8, 1, 1, 16, 32, 2);
    }

    public function testEquals_returnsTrueForSameValues(): void
    {
        $a = new ModelConfig(8, 1, 1, 16, 32, 64);
        $b = new ModelConfig(8, 1, 1, 16, 32, 64);
        $this->assertTrue($a->equals($b));
    }

    public function testTotalAttentionParams_matchesFormula(): void
    {
        $config = new ModelConfig(8, 1, 2, 16, 32, 64);
        $this->assertSame(4 * 8 * 8 * 2, $config->totalAttentionParams());
    }

    public function testTotalFfnParams_matchesFormula(): void
    {
        $config = new ModelConfig(8, 1, 1, 16, 32, 64);
        $perLayer = 8 * 16 + 16 + 16 * 8 + 8;
        $this->assertSame($perLayer, $config->totalFfnParams());
    }
}
