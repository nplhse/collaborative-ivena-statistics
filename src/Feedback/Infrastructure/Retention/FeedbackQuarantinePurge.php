<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Retention;

use App\Feedback\Application\Contract\FeedbackQuarantinePurgeInterface;
use App\Feedback\Domain\Enum\FeedbackClassification;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An entry expires when it went into quarantine (`classified_at`) more than the retention ago; an entry
 * quarantined exactly at the cutoff is kept. Normal feedback is never touched. Only counts are reported.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for FeedbackQuarantinePurgeInterface.
 */
#[AsAlias(FeedbackQuarantinePurgeInterface::class)]
final readonly class FeedbackQuarantinePurge implements FeedbackQuarantinePurgeInterface
{
    private const string EXPIRED = 'FROM feedback WHERE classification = :quarantined AND classified_at < :cutoff';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        #[Autowire(param: 'app.feedback.quarantine.retention_days')]
        private int $retentionDays,
    ) {
    }

    #[\Override]
    public function countExpired(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) '.self::EXPIRED, $this->parameters());
    }

    #[\Override]
    public function purge(): int
    {
        return $this->connection->executeStatement('DELETE '.self::EXPIRED, $this->parameters());
    }

    /** @return array{quarantined: string, cutoff: string} */
    private function parameters(): array
    {
        return [
            'quarantined' => FeedbackClassification::QUARANTINED->value,
            'cutoff' => $this->clock->now()->modify(\sprintf('-%d days', $this->retentionDays))->format('Y-m-d H:i:s'),
        ];
    }
}
