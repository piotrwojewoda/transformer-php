<?php

declare(strict_types=1);

namespace App\Shared\Domain;

// A shared "inbox" for domain events. Command handlers push
// events here, and the EventRecordingMiddleware pulls them out
// and dispatches them as messages.
//
// Why have this in addition to the events stored on each
// aggregate? Because one command handler can call methods on
// MANY aggregates, and we need to gather all their events in
// one place before sending them on their way.
final class DomainEventCollector
{
    /** @var list<DomainEvent> */
    private array $events = [];

    /**
     * Add a single event.
     */
    public function record(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Add every event from an iterable (e.g. all events from
     // one aggregate root).
     *
     * @param iterable<DomainEvent> $events
     */
    public function recordAll(iterable $events): void
    {
        foreach ($events as $event) {
            $this->record($event);
        }
    }

    /**
     * Take all the events out and empty the inbox. The caller
     // is expected to do something with them, like dispatch
     // them onto the message bus.
     *
     * @return list<DomainEvent>
     */
    public function pull(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function count(): int
    {
        return \count($this->events);
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
