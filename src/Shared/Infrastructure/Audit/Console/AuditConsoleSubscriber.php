<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Console;

use App\Shared\Infrastructure\Audit\AuditContext;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * @psalm-suppress UnusedClass
 */
final readonly class AuditConsoleSubscriber
{
    public function __construct(
        private AuditContext $auditContext,
    ) {
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 255)]
    public function onCommand(): void
    {
        $this->auditContext->ensureRequestId(static fn (): string => Uuid::v7()->toRfc4122());
        $this->auditContext->setOrigin(AuditContext::ORIGIN_CLI);
    }
}
