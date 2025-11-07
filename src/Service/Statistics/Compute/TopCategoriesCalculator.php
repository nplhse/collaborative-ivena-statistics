<?php

declare(strict_types=1);

namespace App\Service\Statistics\Compute;

use App\Contract\CalculatorInterface;
use App\Model\Scope;
use App\Service\Statistics\Compute\Sql\ScopePeriodSql;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.calculator', attributes: ['priority' => 40])]
final class TopCategoriesCalculator implements CalculatorInterface
{
    use ScopePeriodSql;

    /**
     * @var array<string, array{fk:string, table:string, label:string, target:string}>
     */
    private const array CAT_MAP = [
        'occasion' => [
            'fk' => 'occasion_id',
            'table' => 'occasion',
            'label' => 'name',
            'target' => 'top_occasion',
        ],
        'assignment' => [
            'fk' => 'assignment_id',
            'table' => 'assignment',
            'label' => 'name',
            'target' => 'top_assignment',
        ],
        'infection' => [
            'fk' => 'infection_id',
            'table' => 'infection',
            'label' => 'name',
            'target' => 'top_infection',
        ],
        'indication_normalized' => [
            'fk' => 'indication_normalized_id',
            'table' => 'indication_normalized',
            'label' => 'name',
            'target' => 'top_indication',
        ],
        'speciality' => [
            'fk' => 'speciality_id',
            'table' => 'speciality',
            'label' => 'name',
            'target' => 'top_speciality',
        ],
        'department' => [
            'fk' => 'department_id',
            'table' => 'department',
            'label' => 'name',
            'target' => 'top_department',
        ],
    ];

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    #[\Override]
    public function supports(Scope $scope): bool
    {
        return true;
    }

    #[\Override]
    public function calculate(Scope $scope): void
    {
        [$fromSql, $whereSql, $params] = $this->buildBaseFilter($scope);

        $limit = 10;

        $tops = [];
        foreach (self::CAT_MAP as $key => $_cfg) {
            $tops[$key] = $this->topForCategory($key, $limit, $fromSql, $whereSql, $params);
        }

        $sql = <<<SQL
INSERT INTO agg_allocations_top_categories (
    scope_type, scope_id, period_gran, period_key,
    top_occasion, top_assignment, top_infection,
    top_indication, top_speciality, top_department
)
VALUES (
    :t, :i, :g, :k::date,
    :top_occasion::jsonb,
    :top_assignment::jsonb,
    :top_infection::jsonb,
    :top_indication::jsonb,
    :top_speciality::jsonb,
    :top_department::jsonb
)
ON CONFLICT (scope_type, scope_id, period_gran, period_key)
DO UPDATE SET
    top_occasion    = EXCLUDED.top_occasion,
    top_assignment  = EXCLUDED.top_assignment,
    top_infection   = EXCLUDED.top_infection,
    top_indication  = EXCLUDED.top_indication,
    top_speciality  = EXCLUDED.top_speciality,
    top_department  = EXCLUDED.top_department,
    computed_at     = now();
SQL;

        $this->db->executeStatement($sql, [
            't' => $scope->scopeType,
            'i' => $scope->scopeId,
            'g' => $scope->granularity,
            'k' => $scope->periodKey,

            'top_occasion' => json_encode($tops['occasion'], JSON_THROW_ON_ERROR),
            'top_assignment' => json_encode($tops['assignment'], JSON_THROW_ON_ERROR),
            'top_infection' => json_encode($tops['infection'], JSON_THROW_ON_ERROR),
            'top_indication' => json_encode($tops['indication_normalized'], JSON_THROW_ON_ERROR),
            'top_speciality' => json_encode($tops['speciality'], JSON_THROW_ON_ERROR),
            'top_department' => json_encode($tops['department'], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param array<string, int|float|string|bool> $params
     *
     * @return list<array{id:int|null,label:string,count:int}>
     */
    private function topForCategory(
        string $category,
        int $limit,
        string $fromSql,
        string $whereSql,
        array $params,
    ): array {
        $cfg = self::CAT_MAP[$category];

        $fk = $cfg['fk'];
        $table = $cfg['table'];
        $labelCol = $cfg['label'];

        // NEU: t.id as id
        $sql = <<<SQL
SELECT
    t.id AS id,
    COALESCE(t.{$labelCol}, 'Unknown') AS label,
    COUNT(*)::int AS count
FROM {$fromSql}
LEFT JOIN {$table} t ON t.id = a.{$fk}
WHERE {$whereSql}
GROUP BY t.id, COALESCE(t.{$labelCol}, 'Unknown')
ORDER BY COUNT(*) DESC, COALESCE(t.{$labelCol}, 'Unknown') ASC
LIMIT :lim
SQL;

        $bind = $params + ['lim' => $limit];

        $rows = $this->db->fetchAllAssociative($sql, $bind);

        return array_map(
            static fn (array $r) => [
                'id' => null !== $r['id'] ? (int) $r['id'] : null,
                'label' => (string) $r['label'],
                'count' => (int) $r['count'],
            ],
            $rows
        );
    }

    /**
     * @return array{
     *   0: string,
     *   1: string,
     *   2: array<string, int|float|string|bool>
     * }
     */
    private function buildBaseFilter(Scope $s): array
    {
        /** @var array{sql: non-empty-string, params: array<string, int|float|string|bool>} $period */
        $period = $this->buildPeriodExpr($s);

        $from = 'allocation a';

        /** @var array<string, int|float|string|bool> $scopeParams */
        $scopeParams = [];

        switch ($s->scopeType) {
            case 'public':
            case 'all':
                $scopeWhere = 'TRUE';
                break;

            case 'hospital':
                $scopeWhere = 'a.hospital_id = :scope_id::int';
                $scopeParams = ['scope_id' => $s->scopeId];
                break;

            case 'dispatch_area':
                $scopeWhere = 'a.dispatch_area_id = :scope_id::int';
                $scopeParams = ['scope_id' => $s->scopeId];
                break;

            case 'state':
                $scopeWhere = 'a.state_id = :scope_id::int';
                $scopeParams = ['scope_id' => $s->scopeId];
                break;

            case 'hospital_tier':
            case 'hospital_size':
            case 'hospital_location':
                $from = 'allocation a JOIN hospital h ON h.id = a.hospital_id';
                $col = substr($s->scopeType, strlen('hospital_'));
                $scopeWhere = "h.{$col} = :hv";
                $scopeParams = ['hv' => $s->scopeId];
                break;

            case 'hospital_cohort':
                $from = 'allocation a JOIN hospital h ON h.id = a.hospital_id';

                [$tier, $location] = array_pad(explode('_', $s->scopeId, 2), 2, null);

                if (null === $tier || null === $location || '' === $tier || '' === $location) {
                    throw new \RuntimeException('Invalid hospital_cohort scopeId, expected "tier_location".');
                }

                $scopeWhere = 'h.tier = :t AND h.location = :l';
                $scopeParams = ['t' => $tier, 'l' => $location];
                break;

            default:
                throw new \RuntimeException('Unknown scopeType: '.$s->scopeType);
        }

        $where = $scopeWhere.' AND '.$period['sql'];
        $params = $scopeParams + $period['params'];

        return [$from, $where, $params];
    }
}
