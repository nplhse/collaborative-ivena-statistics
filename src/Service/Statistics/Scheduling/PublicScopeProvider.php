<?php

declare(strict_types=1);

namespace App\Service\Statistics\Scheduling;

use App\Contract\ScopeProviderInterface;
use App\Model\Scope;
use App\Service\Statistics\Scheduling\Sql\ProviderSql;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.slice_provider', attributes: ['priority' => 80])]
final class PublicScopeProvider implements ScopeProviderInterface
{
    public function __construct(private Connection $db)
    {
    }

    #[\Override]
    public function provideForImport(int $importId): iterable
    {
        $grans = ['year', 'quarter', 'month', 'week', 'day'];

        foreach ($grans as $g) {
            $periodExpr = ProviderSql::periodKeySelect($g);
            $keys = $this->db->fetchFirstColumn(
                "SELECT DISTINCT {$periodExpr} AS k
                   FROM allocation
                  WHERE import_id = :id
               ORDER BY k ASC",
                ['id' => $importId]
            );

            foreach ($keys as $k) {
                yield new Scope('public', 'all', $g, (string) $k);
            }
        }
    }
}
