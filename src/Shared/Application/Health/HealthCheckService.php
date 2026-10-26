<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class HealthCheckService
{
    private const string FAILED_QUEUE_NAME = 'failed';

    /**
     * @param iterable<HealthCheckContributorInterface> $contributors
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private Connection $connection,
        #[Autowire(param: 'app.version')]
        private string $appVersion,
        #[AutowireIterator(HealthCheckContributorInterface::TAG)]
        private iterable $contributors = [],
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
                statuses: ['database' => HealthCheckStatus::Unhealthy],
            );
        }

        $status = HealthCheckStatus::Healthy;
        $checks = ['database' => 'ok'];
        $statuses = ['database' => HealthCheckStatus::Healthy];
        $failedCount = $this->countFailedMessages();

        if ($failedCount > 0) {
            $checks['messenger_failed'] = sprintf('%d failed message(s)', $failedCount);
            $status = HealthCheckStatus::Degraded;
        } else {
            $checks['messenger_failed'] = 'ok';
        }
        $statuses['messenger_failed'] = $status;

        $publicKeys = [];
        foreach ($this->contributors as $contributor) {
            $result = $contributor->check();
            $checks[$result->key] = $result->value;
            $statuses[$result->key] = $result->status;
            $status = $status->worst($result->status);

            if ($result->public) {
                $publicKeys[] = $result->key;
            }
        }

        return new HealthCheckReport($status, $this->appVersion, $checks, $publicKeys, $statuses);
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
