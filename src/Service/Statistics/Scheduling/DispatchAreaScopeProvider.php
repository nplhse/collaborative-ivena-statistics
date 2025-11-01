<?php

declare(strict_types=1);

namespace App\Service\Statistics\Scheduling;

use App\Contract\ScopeProviderInterface;
use App\Model\Scope;
use App\Service\Statistics\Scheduling\Sql\ProviderSql;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.slice_provider', attributes: ['priority' => 100])]
final class DispatchAreaScopeProvider implements ScopeProviderInterface
{
    public function __construct(private Connection $db)
    {
    }

    #[\Override]
    public function provideForImport(int $importId): iterable
    {
        $ids = $this->db->fetchFirstColumn(
            'SELECT DISTINCT dispatch_area_id
               FROM allocation
              WHERE import_id = :id AND dispatch_area_id IS NOT NULL',
            ['id' => $importId]
        );

        $grans = ['year', 'quarter', 'month', 'week', 'day'];

        foreach ($ids as $scopeId) {
            foreach ($grans as $g) {
                $periodExpr = ProviderSql::periodKeySelect($g);
                $keys = $this->db->fetchFirstColumn(
                    "SELECT DISTINCT {$periodExpr} AS k
                       FROM allocation
                      WHERE import_id = :id AND dispatch_area_id = :sid
                   ORDER BY k ASC",
                    ['id' => $importId, 'sid' => $scopeId]
                );

                foreach ($keys as $k) {
                    yield new Scope('dispatch_area', (string) $scopeId, $g, (string) $k);
                }
            }
        }
    }
}
