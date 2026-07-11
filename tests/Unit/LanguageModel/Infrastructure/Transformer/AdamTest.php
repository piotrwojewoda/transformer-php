<?php

declare(strict_types=1);

namespace App\Tests\Unit\LanguageModel\Infrastructure\Transformer;

use App\LanguageModel\Infrastructure\Transformer\Adam;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Adam::class)]
final class AdamTest extends TestCase
{
    public function testStep_matchesHandComputedUpdateForSimpleCase(): void
    {
        $adam = new Adam(0.1);
        $weights = ['p' => [[1.0]]];
        $grads = ['p' => [[0.1]]];
        $new = $adam->step($weights, $grads);
        // First step:
        //   m = 0.9 * 0 + 0.1 * 0.1 = 0.01
        //   v = 0.999 * 0 + 0.001 * 0.01 = 0.00001
        //   w = 1.0 - 0.1 * 0.01 / (sqrt(0.00001) + 1e-8)
        //     = 1.0 - 0.1 * 0.01 / 0.003162...
        //     ≈ 1.0 - 0.31623
        $expected = 1.0 - 0.1 * 0.01 / (\sqrt(0.00001) + 1e-8);
        $this->assertEqualsWithDelta($expected, $new['p'][0][0], 1e-9);
    }

    public function testStep_appliesLearningRate(): void
    {
        $adam = new Adam(1.0);
        $new = $adam->step(['p' => [[0.0]]], ['p' => [[0.0]]]);
        $this->assertSame(0.0, $new['p'][0][0]);
    }

    public function testStep_rejectsNonPositiveLr(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Adam(0.0);
    }

    public function testState_persistsAcrossSteps(): void
    {
        $adam = new Adam(0.01);
        $adam->step(['p' => [[1.0]]], ['p' => [[0.1]]]);
        $state = $adam->getState();
        $this->assertArrayHasKey('p:0:0', $state);
        $this->assertNotSame(0.0, $state['p:0:0']['m']);
    }
}
