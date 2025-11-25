<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Compute\Sql;

use App\Statistics\Domain\Model\Scope;

/** @psalm-suppress ClassMustBeFinal */
class ScopeFilterBuilder
{
    use ScopePeriodSql;

    /**
     * Returns [fromSql, whereSql, params] for the given scope.
     *
     * @return array{0:string,1:string,2:array<string,int|float|string|bool>}
     */
    public function buildBaseFilter(Scope $s): array
    {
        /** @var array{sql: non-empty-string, params: array<string,int|float|string|bool>} $period */
        $period = $this->buildPeriodExpr($s);

        $from = 'allocation a';
        /** @var array<string,int|float|string|bool> $scopeParams */
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
