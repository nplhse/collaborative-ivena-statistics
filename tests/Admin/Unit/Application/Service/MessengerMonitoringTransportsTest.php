<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Application\Service;

use App\Admin\Application\Service\Messenger\MessengerMonitoringService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Messenger page lists the transports of MessengerMonitoringService::TRANSPORTS; a transport added to the
 * configuration must be added there too.
 */
final class MessengerMonitoringTransportsTest extends TestCase
{
    public function testTheTransportListMatchesTheMessengerConfiguration(): void
    {
        /** @var array{framework: array{messenger: array{transports: array<string, mixed>}}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 5).'/config/packages/messenger.yaml');
        $transports = $config['framework']['messenger']['transports'];
        unset($transports['sync']);
        // The failure transport is a DSN string; the others name their Doctrine queue.
        $queues = array_map(
            static fn (mixed $transport): ?string => \is_array($transport) ? ($transport['options']['queue_name'] ?? null) : 'failed',
            $transports,
        );
        // The scheduler transport is not configured in messenger.yaml and has no queue.
        $queues['scheduler_default'] = null;

        self::assertEqualsCanonicalizing($queues, MessengerMonitoringService::TRANSPORTS);
    }
}
