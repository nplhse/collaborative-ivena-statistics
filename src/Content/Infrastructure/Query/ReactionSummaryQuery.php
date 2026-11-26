<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Query;

use App\Content\Application\Contract\ReactionSummaryQueryInterface;
use App\Content\Application\Reaction\ReactionCount;
use App\Content\Application\Reaction\ReactionParticipant;
use App\Content\Application\Reaction\ReactionSummary;
use App\Content\Domain\Enum\ReactionType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Counts, own reaction, tooltip names and the latest participants of many targets with at most two queries per target
 * type (#467, #973), so a page of the timeline or all comments of a post cost no query per item.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for ReactionSummaryQueryInterface.
 */
#[AsAlias(ReactionSummaryQueryInterface::class)]
final readonly class ReactionSummaryQuery implements ReactionSummaryQueryInterface
{
    /** People in the avatar stack of a reaction bar. */
    private const int MAX_PARTICIPANTS = 4;

    /** Joins the names in SQL; a control character no display name contains. */
    private const string NAME_SEPARATOR = "\x1F";

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function summaries(array $targets, ?int $viewerId, bool $withNames): array
    {
        $idsByType = [];
        foreach ($targets as $target) {
            $idsByType[$target->type->value][] = $target->id;
        }

        /** @var array<string, array<string, ReactionCount>> $counts by target key, then reaction type */
        $counts = [];
        /** @var array<string, list<ReactionParticipant>> $participants by target key */
        $participants = [];
        foreach ($idsByType as $type => $ids) {
            if ($withNames) {
                $participants += $this->participants($type, array_values(array_unique($ids)));
            }

            $rows = $this->connection->fetchAllAssociative(
                'SELECT r.target_id, r.type, COUNT(*) AS reactions,
                        COALESCE(BOOL_OR(r.user_id = :viewer), FALSE) AS own,
                        ARRAY_TO_STRING((ARRAY_AGG(COALESCE(NULLIF(TRIM(u.display_name), \'\'), u.username)
                            ORDER BY r.created_at, r.id))[1:'.self::MAX_NAMES.'], :separator) AS names
                 FROM reaction r
                 INNER JOIN "user" u ON u.id = r.user_id AND u.is_enabled = TRUE
                 WHERE r.target_type = :type AND r.target_id IN (:ids)
                 GROUP BY r.target_id, r.type',
                ['viewer' => $viewerId, 'separator' => self::NAME_SEPARATOR, 'type' => $type, 'ids' => array_values(array_unique($ids))],
                ['viewer' => null === $viewerId ? ParameterType::NULL : ParameterType::INTEGER, 'ids' => ArrayParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $reactionType = ReactionType::tryFrom((string) $row['type']);
                if (!$reactionType instanceof ReactionType) {
                    continue;
                }

                $names = $withNames && '' !== (string) $row['names'] ? explode(self::NAME_SEPARATOR, (string) $row['names']) : [];
                $counts[$type.':'.(int) $row['target_id']][$reactionType->value] = new ReactionCount(
                    $reactionType,
                    (int) $row['reactions'],
                    null !== $viewerId && \in_array($row['own'], [true, 1, '1', 't', 'true'], true),
                    $names,
                );
            }
        }

        $summaries = [];
        foreach ($targets as $target) {
            $byType = $counts[$target->key()] ?? [];
            $ordered = [];
            foreach (ReactionType::cases() as $reactionType) {
                if (isset($byType[$reactionType->value])) {
                    $ordered[] = $byType[$reactionType->value];
                }
            }
            $summaries[$target->key()] = new ReactionSummary($target, $ordered, $participants[$target->key()] ?? []);
        }

        return $summaries;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<string, list<ReactionParticipant>> by target key
     */
    private function participants(string $type, array $ids): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT target_id, user_id, username, display_name FROM (
                 SELECT r.target_id, r.id, u.id AS user_id, u.username, u.display_name,
                        ROW_NUMBER() OVER (PARTITION BY r.target_id ORDER BY r.id DESC) AS position
                 FROM reaction r
                 INNER JOIN "user" u ON u.id = r.user_id AND u.is_enabled = TRUE
                 WHERE r.target_type = :type AND r.target_id IN (:ids)
             ) ranked
             WHERE position <= :limit
             ORDER BY target_id, position',
            ['type' => $type, 'ids' => $ids, 'limit' => self::MAX_PARTICIPANTS],
            ['ids' => ArrayParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        $participants = [];
        foreach ($rows as $row) {
            $participants[$type.':'.(int) $row['target_id']][] = new ReactionParticipant(
                (int) $row['user_id'],
                (string) $row['username'],
                null === $row['display_name'] ? null : (string) $row['display_name'],
            );
        }

        return $participants;
    }
}
