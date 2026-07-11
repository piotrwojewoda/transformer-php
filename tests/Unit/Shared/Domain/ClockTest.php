<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\LanguageModel\Domain\Event\ModelCreated;
use App\LanguageModel\Domain\Model\ModelConfig;
use App\LanguageModel\Domain\Model\ModelId;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventCollector;
use App\Shared\Infrastructure\Clock\MockClock;
use App\Shared\Infrastructure\Clock\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
#[CoversClass(MockClock::class)]
#[CoversClass(DomainEventCollector::class)]
#[CoversClass(AggregateRoot::class)]
final class ClockTest extends TestCase
{
    public function testSystemClock_returnsImmutableInUtc(): void
    {
        $clock = new SystemClock();
        $now = $clock->now();
        $this->assertInstanceOf(\DateTimeImmutable::class, $now);
        $this->assertSame('UTC', $now->getTimezone()->getName());
    }

    public function testMockClock_returnsPinnedValue(): void
    {
        $fixed = new \DateTimeImmutable('2025-01-01T00:00:00Z');
        $clock = new MockClock($fixed);
        $this->assertSame($fixed, $clock->now());
    }

    public function testMockClock_advanceMovesForward(): void
    {
        $clock = new MockClock();
        $before = $clock->now();
        $clock->advance('1 hour');
        $this->assertGreaterThan($before, $clock->now());
    }

    public function testDomainEventCollector_recordsAndPulls(): void
    {
        $c = new DomainEventCollector();
        $event = new ModelCreated(ModelId::create(), new ModelConfig(4, 1, 1, 8, 16, 8));
        $c->record($event);
        $this->assertSame(1, $c->count());
        $pulled = $c->pull();
        $this->assertCount(1, $pulled);
        $this->assertSame(0, $c->count());
    }
}
