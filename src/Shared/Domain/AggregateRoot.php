<?php

declare(strict_types=1);

namespace App\Shared\Domain;

// A "domain event" is a small message that says "something
// important happened". An "aggregate root" is the main object
// of a business concept (like LanguageModel or TrainingJob).
//
// Why give aggregates a list of events?
// Because the rest of the system often wants to react to what
// happened (update a read model, send an email, log to a file).
// Instead of doing all that work inside the aggregate itself,
// it just records the events, and the EventRecordingMiddleware
// (or similar) picks them up afterwards.
//
// This is the base class: it knows how to record events and how
// to give them out to whoever asks.
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    /**
     * Save an event in the in-memory list. The aggregate calls
     // this whenever something interesting happens (e.g.
     // model->markTrained() records a ModelTrained event).
     */
    final protected function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * Give all recorded events to the caller and clear the list.
     // The caller is expected to actually do something with them
     // (typically: dispatch them as messages).
     *
     * @return list<DomainEvent>
     */
    final public function pullDomainEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    public function recordedEventsCount(): int
    {
        return \count($this->recordedEvents);
    }
}
