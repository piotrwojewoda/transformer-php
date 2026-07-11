<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\DomainEventCollector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;

// A "middleware" in Symfony Messenger is a small piece of code
// that sits in the message-handling pipeline and can wrap the
// call (do something before, do something after).
//
// This one is responsible for taking any domain events the
// command handler pushed into the DomainEventCollector and
// re-dispatching them as messages. That way they can be routed
// elsewhere (e.g. to a different worker) or persisted as a log.
//
// We only do this for FRESH messages (not ones a worker pulled
// from the queue), because re-dispatching them would create an
// infinite loop. We detect that with the ConsumedByWorkerStamp.
final class EventRecordingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DomainEventCollector $collector,
        private readonly MessageBusInterface $commandBus,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Let the rest of the pipeline (i.e. the actual handler)
        // run first. The handler will populate the collector.
        $envelope = $stack->next()->handle($envelope, $stack);

        // If this message is being processed by a worker (it was
        // pulled from a queue), do NOT re-dispatch its events,
        // because that would loop forever.
        $isConsumed = $envelope->last(ConsumedByWorkerStamp::class) !== null;
        if ($isConsumed) {
            return $envelope;
        }

        // For fresh messages: pull every collected event and
        // dispatch it through the bus.
        foreach ($this->collector->pull() as $event) {
            $this->commandBus->dispatch($event);
            $this->logger?->info('Domain event dispatched', [
                'event' => $event::class,
                'occurredAt' => $event->occurredAt()->format(\DateTimeInterface::ATOM),
            ]);
        }

        return $envelope;
    }
}
