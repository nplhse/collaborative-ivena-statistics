<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

use App\Encounter\Application\Dimension\EdVisitDimension;
use App\Encounter\Application\Dimension\EncounterField;

/**
 * One synthetic export of a hospital: the visits whose day lies in its range, as the source showed
 * them at the time of the export (#1052).
 */
final readonly class EdVisitExport
{
    public const string PROFILE = 'synthetic';
    public const int PROFILE_VERSION = 1;

    /**
     * @param list<array{visit: SyntheticVisit, cells: array<string, string>}> $rows
     */
    public function __construct(
        public string $name,
        public array $rows,
    ) {
    }

    /**
     * @return list<SyntheticVisit>
     */
    public function visits(): array
    {
        return array_map(static fn (array $row): SyntheticVisit => $row['visit'], $this->rows);
    }

    /**
     * The canonical `ed_visit` v1 file (exchange-format.md): manifest, header, one row per visit; UTF-8,
     * `;`, LF, final newline. No cell needs quoting: values are codes, numbers, timestamps and catalog
     * names without `;`.
     */
    public function content(SyntheticEncounterKey $key, string $toolVersion): string
    {
        $header = array_map(static fn (EncounterField $field): string => $field->name, new EdVisitDimension()->fields(1));
        $lines = [
            implode(';', [
                '#encounter-exchange',
                'schema='.EdVisitDimension::SCHEMA,
                'schema_version=1',
                'profile='.self::PROFILE,
                'profile_version='.self::PROFILE_VERSION,
                'tool='.$toolVersion,
                'key_check='.$key->checkValue(),
                'fingerprint='.EdVisitDimension::FINGERPRINT_RULE_V1,
                'rows='.\count($this->rows),
            ]),
            implode(';', $header),
        ];

        foreach ($this->rows as $row) {
            $cells = $key->keysOf($row['visit']->caseNumber, $row['cells']) + $row['cells'];
            $lines[] = implode(';', array_map(static function (string $field) use ($cells): string {
                $value = $cells[$field] ?? '';
                if (1 === preg_match('/[;"\r\n]/', $value)) {
                    throw new \LogicException(sprintf('Synthetic value of "%s" needs quoting.', $field));
                }

                return $value;
            }, $header));
        }

        return implode("\n", $lines)."\n";
    }
}
