<?php

declare(strict_types=1);

namespace App\Service\Statistics\Scheduling;

use App\Contract\ScopeProviderInterface;
use App\Model\Scope;
use App\Service\Statistics\Scheduling\Sql\ProviderSql;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.slice_provider', attributes: ['priority' => 200])]
final class HospitalScopeProvider implements ScopeProviderInterface
{
    public function __construct(private Connection $db)
    {
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

        $grans = ['year', 'quarter', 'month', 'week', 'day'];

        foreach ($hospitalIds as $hid) {
            foreach ($grans as $g) {
                $periodExpr = ProviderSql::periodKeySelect($g);
                $keys = $this->db->fetchFirstColumn(
                    "SELECT DISTINCT {$periodExpr} AS k
                       FROM allocation
                      WHERE import_id = :id AND hospital_id = :hid
                   ORDER BY k ASC",
                    ['id' => $importId, 'hid' => $hid]
                );

                foreach ($keys as $k) {
                    yield new Scope('hospital', (string) $hid, $g, (string) $k);
                }
            }
        }
    }
}
