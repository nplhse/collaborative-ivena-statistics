<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Database;

use App\Shared\Application\Database\DbalManagedTablesInterface;

/**
 * Tables of the Messenger monitoring (ADR 019), written and read with DBAL; no entity maps them (#1372).
 *
 * @psalm-suppress UnusedClass Tagged via DbalManagedTablesInterface.
 */
final readonly class MessengerMonitoringDbalTables implements DbalManagedTablesInterface
{
    #[\Override]
    public function tables(): array
    {
        return ['messenger_failure', 'messenger_metric', 'messenger_queue_sample'];
    }
}
