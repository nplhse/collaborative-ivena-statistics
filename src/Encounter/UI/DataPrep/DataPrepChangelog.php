<?php

declare(strict_types=1);

namespace App\Encounter\UI\DataPrep;

/**
 * What changed per version of the offline data preparation tool (#1130), shown on the tool's landing
 * page, newest first. Maintained here, not taken from the git history: add an entry with every raise of
 * `app.encounter.data_prep.version` (DataPrepChangelogTest checks that the newest entry is the
 * configured version). The notes are translation keys of the `encounter` domain.
 */
final class DataPrepChangelog
{
    /**
     * @var list<array{version: string, released_at: string, notes: list<string>}>
     */
    private const array ENTRIES = [
        [
            'version' => '1.2.0',
            'released_at' => '2026-11-14',
            'notes' => [
                'encounter.data_prep.changelog.1_2_0.catalog_from_server',
                'encounter.data_prep.changelog.1_2_0.aliases',
                'encounter.data_prep.changelog.1_2_0.new_diagrams',
            ],
        ],
        [
            'version' => '1.1.0',
            'released_at' => '2026-11-13',
            'notes' => [
                'encounter.data_prep.changelog.1_1_0.map_editor',
                'encounter.data_prep.changelog.1_1_0.map_remember',
            ],
        ],
        [
            'version' => '1.0.2',
            'released_at' => '2026-11-12',
            'notes' => [
                'encounter.data_prep.changelog.1_0_2.department_names_checked',
            ],
        ],
        [
            'version' => '1.0.1',
            'released_at' => '2026-11-12',
            'notes' => [
                'encounter.data_prep.changelog.1_0_1.department_names',
                'encounter.data_prep.changelog.1_0_1.mts_indicators',
            ],
        ],
        [
            'version' => '1.0.0',
            'released_at' => '2026-11-12',
            'notes' => [
                'encounter.data_prep.changelog.1_0_0.first_release',
            ],
        ],
    ];

    /**
     * @return list<array{version: string, released_at: string, notes: list<string>}>
     */
    public function entries(): array
    {
        return self::ENTRIES;
    }
}
