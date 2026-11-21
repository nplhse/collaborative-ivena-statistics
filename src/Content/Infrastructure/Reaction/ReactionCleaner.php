<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Reaction;

use App\Content\Application\Contract\ReactionCleanerInterface;
use App\Content\Application\Reaction\ReactionTargetRegistry;
use App\Content\Domain\Enum\ReactionTargetType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Deletes reactions whose target was deleted (#467). Targets are referenced without a foreign key, so their
 * reactions stay behind until this runs; they are never shown, because only reactions of displayed targets are read.
 * Reactions of a target type without provider are left alone: a removed provider is a code change, not a deletion.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for ReactionCleanerInterface.
 */
#[AsAlias(ReactionCleanerInterface::class)]
final readonly class ReactionCleaner implements ReactionCleanerInterface
{
    private const int BATCH_SIZE = 500;

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
        private ReactionTargetRegistry $targets,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function removeOrphans(): array
    {
        $deleted = [];
        foreach ($this->targets->all() as $provider) {
            $type = $provider->targetType();
            $deleted[$type->value] = $this->removeOrphansOf($type, $provider->existingIds(...));
        }

        return $deleted;
    }

    /**
     * @param \Closure(list<int>): list<int> $existingIds
     */
    private function removeOrphansOf(ReactionTargetType $type, \Closure $existingIds): int
    {
        $deleted = 0;
        $afterId = 0;
        do {
            $ids = array_map(intval(...), $this->connection->fetchFirstColumn(
                'SELECT DISTINCT target_id FROM reaction WHERE target_type = :type AND target_id > :after ORDER BY target_id LIMIT '.self::BATCH_SIZE,
                ['type' => $type->value, 'after' => $afterId],
            ));
            if ([] === $ids) {
                break;
            }

            $missing = array_values(array_diff($ids, $existingIds($ids)));
            if ([] !== $missing) {
                $removed = \count($this->connection->fetchFirstColumn(
                    'DELETE FROM reaction WHERE target_type = :type AND target_id IN (:ids) RETURNING id',
                    ['type' => $type->value, 'ids' => $missing],
                    ['ids' => ArrayParameterType::INTEGER],
                ));
                $deleted += $removed;
                $this->logger->info('Removed reactions of deleted targets.', ['target_type' => $type->value, 'targets' => \count($missing), 'reactions' => $removed]);
            }

            $afterId = $ids[\count($ids) - 1];
        } while (self::BATCH_SIZE === \count($ids));

        return $deleted;
    }
}
