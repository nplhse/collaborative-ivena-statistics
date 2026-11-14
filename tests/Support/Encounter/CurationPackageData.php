<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

/**
 * Small hand-made encounter curation packages (#1198) as arrays, sealed with counts and checksum like an export.
 *
 * Entries named "seeded" are the exact rows of the seed migrations, so an import counts them as unchanged.
 */
final class CurationPackageData
{
    private const int JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    /**
     * @return array{code: string, german_name: string, english_name: ?string, source: string}
     */
    public static function entry(string $code, string $germanName, ?string $englishName = null, string $source = 'manual'): array
    {
        return ['code' => $code, 'german_name' => $germanName, 'english_name' => $englishName, 'source' => $source];
    }

    /**
     * @return array{code: string, german_name: string, english_name: ?string, source: string}
     */
    public static function seededAllergy(): array
    {
        return self::entry('allergie', 'Allergie', 'Allergy', 'orbis_export');
    }

    /**
     * @return list<array{code: string, german_name: string, english_name: ?string, source: string}>
     */
    public static function seededTraumaDiagrams(): array
    {
        return [
            self::entry('abdominelle_schmerzen_bei_erwachsenen', 'Abdominelle Schmerzen bei Erwachsenen', 'Abdominal pain in adults', 'orbis_export'),
            self::entry('schweres_trauma', 'Schweres Trauma', 'Major trauma', 'orbis_export'),
        ];
    }

    /**
     * The seeded area "Abdominaltrauma": PZC 241, 242; both trauma diagrams at 3 points.
     *
     * @param list<int>                                   $pzc
     * @param list<array{code: string, points: int}>|null $rules
     *
     * @return array<string, mixed>
     */
    public static function seededAbdominalTrauma(array $pzc = [241, 242], ?array $rules = null): array
    {
        return self::area('abdominaltrauma', 'Abdominaltrauma', 'Abdominal trauma', $pzc, $rules ?? [
            ['code' => 'abdominelle_schmerzen_bei_erwachsenen', 'points' => 3],
            ['code' => 'schweres_trauma', 'points' => 3],
        ]);
    }

    /**
     * @param list<int>                              $pzc
     * @param list<array{code: string, points: int}> $rules
     *
     * @return array<string, mixed>
     */
    public static function area(string $code, string $germanName, ?string $englishName, array $pzc, array $rules): array
    {
        return ['code' => $code, 'german_name' => $germanName, 'english_name' => $englishName, 'pzc' => $pzc, 'diagrams' => $rules];
    }

    /**
     * What a new instance lacks: 2 new diagrams (plus the seeded "Allergie"), 1 indicator, 1 assignment, 2 aliases
     * (one of them the code of a former merge) and a new area with PZC 990, 991 and one rule at 3 points.
     *
     * @return array<string, mixed>
     */
    public static function newInstance(): array
    {
        return self::package(
            diagrams: [
                self::seededAllergy(),
                self::entry('augenprobleme_bei_kindern', 'Augenprobleme bei Kindern', 'Eye problems in children'),
                self::entry('hautprobleme', 'Hautprobleme', null, 'mts_edition'),
            ],
            indicators: [self::entry('juckreiz', 'Juckreiz', 'Itching')],
            aliases: [
                ['kind' => 'diagram', 'code' => 'augenprobleme_kinder', 'label' => 'Augenprobleme Kinder', 'target_code' => 'augenprobleme_bei_kindern'],
                ['kind' => 'diagram', 'code' => 'hautproblem_alt', 'label' => 'Hautproblem (alt)', 'target_code' => 'hautprobleme'],
            ],
            assignments: [['diagram' => 'hautprobleme', 'indicator' => 'juckreiz']],
            areas: [self::area('haut_und_augen', 'Haut und Augen', 'Skin and eyes', [990, 991], [['code' => 'hautprobleme', 'points' => 3]])],
        );
    }

    /**
     * @param list<array<string, mixed>> $diagrams
     * @param list<array<string, mixed>> $indicators
     * @param list<array<string, mixed>> $aliases
     * @param list<array<string, mixed>> $assignments
     * @param list<array<string, mixed>> $areas
     *
     * @return array<string, mixed>
     */
    public static function package(array $diagrams = [], array $indicators = [], array $aliases = [], array $assignments = [], array $areas = []): array
    {
        return self::seal([
            'meta' => [
                'format' => 'ivena-encounter-curation',
                'version' => 1,
                'exported_at' => '2026-11-14T12:00:00+00:00',
                'counts' => [],
                'checksum' => ['algorithm' => 'sha256', 'value' => str_repeat('0', 64)],
                'app_version' => 'test',
                'source_environment' => 'staging',
                'notes' => null,
            ],
            'diagrams' => $diagrams,
            'indicators' => $indicators,
            'aliases' => $aliases,
            'assignments' => $assignments,
            'problem_areas' => $areas,
        ]);
    }

    /**
     * Recomputes counts and checksum after a test changed the data, as an export would write them.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function seal(array $data): array
    {
        $sections = $data;
        unset($sections['meta']);

        /** @var array<string, mixed> $meta */
        $meta = $data['meta'];
        $meta['counts'] = array_map(static fn (mixed $section): int => \is_array($section) ? \count($section) : 0, $sections);
        $meta['checksum'] = ['algorithm' => 'sha256', 'value' => hash('sha256', json_encode($sections, self::JSON_FLAGS))];
        $data['meta'] = $meta;

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | self::JSON_FLAGS)."\n";
    }
}
