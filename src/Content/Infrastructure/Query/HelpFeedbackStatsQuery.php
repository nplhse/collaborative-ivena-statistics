<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Query;

use App\Content\Application\Contract\HelpFeedbackStatsQueryInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Answers per help article with one query, kept for the request (the admin list asks once per row).
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for HelpFeedbackStatsQueryInterface.
 */
#[AsAlias(HelpFeedbackStatsQueryInterface::class)]
final class HelpFeedbackStatsQuery implements HelpFeedbackStatsQueryInterface
{
    /** @var array<int, array{helpful: int, unhelpful: int}>|null */
    private ?array $counts = null;

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[\Override]
    public function countsByPage(): array
    {
        if (null !== $this->counts) {
            return $this->counts;
        }

        $counts = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT page_id, COUNT(*) FILTER (WHERE helpful) AS helpful, COUNT(*) FILTER (WHERE NOT helpful) AS unhelpful
             FROM help_feedback GROUP BY page_id',
        ) as $row) {
            $counts[(int) $row['page_id']] = ['helpful' => (int) $row['helpful'], 'unhelpful' => (int) $row['unhelpful']];
        }

        return $this->counts = $counts;
    }
}
