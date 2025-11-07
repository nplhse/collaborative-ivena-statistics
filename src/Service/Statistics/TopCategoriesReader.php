<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use Doctrine\DBAL\Connection;

final readonly class TopCategoriesReader
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $db,
    ) {
    }

    /**
     * @return array{
     *   total:int,
     *   computedAt:\DateTimeImmutable|null,
     *   occasion:   array{items:list<array{id:int|null,label:string,count:int}>},
     *   assignment: array{items:list<array{id:int|null,label:string,count:int}>},
     *   infection:  array{items:list<array{id:int|null,label:string,count:int}>},
     *   indication: array{items:list<array{id:int|null,label:string,count:int}>},
     *   speciality: array{items:list<array{id:int|null,label:string,count:int}>},
     *   department: array{items:list<array{id:int|null,label:string,count:int}>}
     * }
     */
    public function read(string $scopeType, string $scopeId, string $gran, string $key): array
    {
        /** @var array{
         *   total?: int|string|null,
         *   computed_at?: string|null,
         *   top_occasion?: mixed,
         *   top_assignment?: mixed,
         *   top_infection?: mixed,
         *   top_indication?: mixed,
         *   top_speciality?: mixed,
         *   top_department?: mixed
         * }|false $row
         */
        $row = $this->db->fetchAssociative(
            <<<SQL
            SELECT
                total,
                computed_at,
                top_occasion,
                top_assignment,
                top_infection,
                top_indication,
                top_speciality,
                top_department
            FROM agg_allocations_top_categories
            WHERE scope_type = :t
              AND scope_id   = :i
              AND period_gran = :g
              AND period_key  = :k::date
            SQL,
            ['t' => $scopeType, 'i' => $scopeId, 'g' => $gran, 'k' => $key]
        );

        $computedAt = null;
        if (false !== $row && array_key_exists('computed_at', $row) && is_string($row['computed_at']) && '' !== $row['computed_at']) {
            $computedAt = new \DateTimeImmutable($row['computed_at']);
        }

        /**
         * @phpstan-param mixed $v
         *
         * @phpstan-return list<array{id:int|null,label:string,count:int}>
         */
        $decode = static function (mixed $v): array {
            if (is_string($v)) {
                $v = json_decode($v, true);
            }
            if (!is_array($v)) {
                /* @var list<array{id:int|null,label:string,count:int}> */
                return [];
            }

            $out = [];
            foreach ($v as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $id = array_key_exists('id', $r) && null !== $r['id'] ? (int) $r['id'] : null;
                $label = isset($r['label']) ? (string) $r['label'] : 'Unknown';
                $count = isset($r['count']) ? (int) $r['count'] : 0;

                $item = ['id' => $id, 'label' => $label, 'count' => $count];
                $out[] = $item;
            }

            return $out;
        };

        $cntRaw = (false !== $row && array_key_exists('total', $row)) ? $row['total'] : 0;
        $cnt = is_int($cntRaw) ? $cntRaw : (int) $cntRaw;

        return [
            'total' => $cnt,
            'computedAt' => $computedAt,
            'occasion' => ['items' => $decode(false !== $row ? ($row['top_occasion'] ?? []) : [])],
            'assignment' => ['items' => $decode(false !== $row ? ($row['top_assignment'] ?? []) : [])],
            'infection' => ['items' => $decode(false !== $row ? ($row['top_infection'] ?? []) : [])],
            'indication' => ['items' => $decode(false !== $row ? ($row['top_indication'] ?? []) : [])],
            'speciality' => ['items' => $decode(false !== $row ? ($row['top_speciality'] ?? []) : [])],
            'department' => ['items' => $decode(false !== $row ? ($row['top_department'] ?? []) : [])],
        ];
    }
}
