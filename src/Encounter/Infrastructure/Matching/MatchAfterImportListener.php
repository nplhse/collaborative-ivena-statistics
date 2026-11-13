<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Matching;

use App\Encounter\Application\Event\EncountersImported;
use App\Encounter\Application\Matching\MatchScope;
use App\Encounter\Application\Message\MatchEncountersMessage;
use App\Encounter\Infrastructure\Query\MatchTriggerQuery;
use App\Import\Application\Event\ImportCompleted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a matcher run for the affected hospital-days after an import (ADR 030, #1053):
 *
 * - `EncountersImported`: the days of the new visits, widened by one day on both sides.
 * - `ImportCompleted` of an allocation import: the arrival days of its allocations, only when the
 *   hospital has encounter data. Encounter listens to Import; Import knows nothing of the matcher.
 *
 * @psalm-suppress UnusedClass Registered as event listener.
 */
final readonly class MatchAfterImportListener
{
    public function __construct(
        private MessageBusInterface $bus,
        private MatchTriggerQuery $triggers,
    ) {
    }

    #[AsEventListener(event: EncountersImported::class)]
    public function onEncountersImported(EncountersImported $event): void
    {
        if ([] === $event->days) {
            return;
        }

        $this->bus->dispatch(new MatchEncountersMessage($event->hospitalId, MatchScope::aroundVisitDays($event->hospitalId, $event->days)->days));
    }

    #[AsEventListener(event: ImportCompleted::class)]
    public function onImportCompleted(ImportCompleted $event): void
    {
        $affected = $this->triggers->allocationImportDays($event->importId);
        if (null === $affected || [] === $affected['days']) {
            return;
        }

        $this->bus->dispatch(new MatchEncountersMessage($affected['hospitalId'], $affected['days']));
    }
}
