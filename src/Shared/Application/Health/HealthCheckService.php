<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class HealthCheckService
{
    private const string FAILED_QUEUE_NAME = 'failed';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $connection,
        #[Autowire('%app.version%')]
        private string $appVersion,
    ) {
    }

    public function check(): HealthCheckReport
    {
        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (Exception) {
            return new HealthCheckReport(
                HealthCheckStatus::Unhealthy,
                $this->appVersion,
                [
                    'database' => 'unreachable',
                    'messenger_failed' => 'skipped',
                ],
            );
        }

        $checks = ['database' => 'ok'];
        $failedCount = $this->countFailedMessages();

        if ($failedCount > 0) {
            $checks['messenger_failed'] = sprintf('%d failed message(s)', $failedCount);

            return new HealthCheckReport(
                HealthCheckStatus::Degraded,
                $this->appVersion,
                $checks,
            );
        }

        $checks['messenger_failed'] = 'ok';

        return new HealthCheckReport(
            HealthCheckStatus::Healthy,
            $this->appVersion,
            $checks,
        );
    }

    private function countFailedMessages(): int
    {
        $result = $this->connection->executeQuery(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => self::FAILED_QUEUE_NAME],
        );

        return (int) $result->fetchOne();
    }
}
