<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Scheduling;

use App\Statistics\Application\Contract\ScopeProviderInterface;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Scheduling\Sql\ProviderSql;
use App\Statistics\Infrastructure\Util\Period;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.scope_provider', attributes: ['priority' => 200])]
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

        $grans = Period::allGranularities();

        foreach ($hospitalIds as $hid) {
            foreach ($grans as $g) {
                if (Period::ALL === $g) {
                    yield new Scope('hospital', (string) $hid, $g, Period::ALL_ANCHOR_DATE);
                    continue;
                }

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
