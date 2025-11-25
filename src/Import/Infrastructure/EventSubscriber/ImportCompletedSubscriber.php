<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Application\Event\ImportCompleted;
use App\Statistics\Application\Message\ScheduleScope;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener(event: ImportCompleted::class, method: 'onImportCompleted')]
final readonly class ImportCompletedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [ImportCompleted::class => 'onImportCompleted'];
    }

    public function onImportCompleted(ImportCompleted $event): void
    {
        $this->messageBus->dispatch(new ScheduleScope($event->importId));
    }
}
