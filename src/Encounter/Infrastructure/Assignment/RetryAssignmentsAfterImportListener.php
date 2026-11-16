<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Assignment;

use App\Encounter\Application\Event\EncountersImported;
use App\Encounter\Application\Message\RetryEncounterAssignmentsMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An encounter import that added encounters can give waiting records a candidate (#1307). EncountersImported
 * is dispatched after the run committed, so the retry sees the new encounters. Runs after the matcher's
 * listener; a record that names an allocation is retried again only after a later encounter import or by the
 * command, if the link is created by a matcher run that finishes later.
 *
 * @psalm-suppress UnusedClass Registered via #[AsEventListener].
 */
final readonly class RetryAssignmentsAfterImportListener
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    #[AsEventListener(event: EncountersImported::class, priority: -10)]
    public function onEncountersImported(EncountersImported $event): void
    {
        $this->bus->dispatch(new RetryEncounterAssignmentsMessage($event->hospitalId));
    }
}
