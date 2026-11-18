<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Infrastructure\Query;

use App\Encounter\Contract\KeyChange\KeyChangeFigures;
use App\Encounter\Contract\KeyChange\KeyChangeFiguresProviderInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What a key change with "delete" removes of the ED match review (#1053, #1375): effective IVENA links
 * (automatic or confirmed) and manual decisions (confirmed or rejected) in the matching history `encounter_match`.
 *
 * @psalm-suppress UnusedClass Tagged via KeyChangeFiguresProviderInterface.
 */
final readonly class KeyChangeFiguresQuery implements KeyChangeFiguresProviderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function figuresForHospital(int $hospitalId): KeyChangeFigures
    {
        return new KeyChangeFigures(
            links: $this->countMatches($hospitalId, ['auto_accepted', 'confirmed']),
            decisions: $this->countMatches($hospitalId, ['confirmed', 'rejected']),
        );
    }

    /**
     * @param list<string> $statuses
     */
    private function countMatches(int $hospitalId, array $statuses): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM encounter_match WHERE hospital_id = :hospital AND status IN (:statuses)',
            ['hospital' => $hospitalId, 'statuses' => $statuses],
            ['statuses' => ArrayParameterType::STRING],
        );
    }
}
