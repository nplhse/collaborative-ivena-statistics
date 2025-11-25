<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Scheduling;

use App\Statistics\Application\Contract\ScopeProviderInterface;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Scheduling\Sql\ProviderSql;
use App\Statistics\Infrastructure\Util\Period;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.scope_provider', attributes: ['priority' => 150])]
final class CohortScopeProvider implements ScopeProviderInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function provideForImport(int $importId): iterable
    {
        $hospitalIds = $this->db->fetchFirstColumn(
            'SELECT DISTINCT hospital_id
               FROM allocation
              WHERE import_id = :id AND hospital_id IS NOT NULL',
            ['id' => $importId]
        );

        if ([] === $hospitalIds) {
            $this->logger->warning('No hospital found for import', ['import_id' => $importId]);

            return;
        }

        $hospitalId = (int) $hospitalIds[0];

        $meta = $this->db->fetchAssociative(
            'SELECT h.tier, h.size, h.location
                    FROM hospital h
                WHERE h.id = :hid',
            ['hid' => $hospitalId]
        );

        if (false === $meta) {
            $meta = ['tier' => null, 'size' => null, 'location' => null];
        }

        $tier = $meta['tier'] ? (string) $meta['tier'] : null;
        $size = $meta['size'] ? (string) $meta['size'] : null;
        $location = $meta['location'] ? (string) $meta['location'] : null;

        $grans = Period::allGranularities();

        foreach ($grans as $g) {
            $periodExpr = ProviderSql::periodKeySelect($g);
            $keys = $this->db->fetchFirstColumn(
                "SELECT DISTINCT {$periodExpr} AS k
                   FROM allocation
                  WHERE import_id = :id
               ORDER BY k ASC",
                ['id' => $importId]
            );

            foreach ($keys as $kRaw) {
                $k = (string) $kRaw;

                if (null !== $tier && null !== $location) {
                    yield new Scope('hospital_cohort', "{$tier}_{$location}", $g, $k);
                } elseif (null !== $tier) {
                    yield new Scope('hospital_tier', $tier, $g, $k);
                } elseif (null !== $size) {
                    yield new Scope('hospital_size', $size, $g, $k);
                } elseif (null !== $location) {
                    yield new Scope('hospital_location', $location, $g, $k);
                }
            }
        }
    }
}
