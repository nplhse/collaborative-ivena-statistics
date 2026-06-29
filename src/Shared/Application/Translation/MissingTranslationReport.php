<?php

declare(strict_types=1);

namespace App\Shared\Application\Translation;

final class MissingTranslationReport
{
    /** @var array<string, list<string>> */
    public const array WAVES = [
        'shared' => [
            'label.',
            'link.',
            'btn.',
            'action.',
            'flash.',
            'help.',
            'title.',
            'field.',
            'locale.',
            'cookie.',
            'email.',
            'feedback.',
            'abbr.',
            'info.',
            'warning.',
            'error.',
            'text.',
        ],
        'content' => [
            'public.',
            'blog.',
            'dashboard.',
            'page.',
            'onboarding.',
        ],
        'stats' => [
            'stats.',
            'statistics.',
        ],
        'import' => [
            'import.',
            'indication.',
            'allocation.',
        ],
    ];

    public function __construct(
        private readonly string $translationsDirectory,
    ) {
    }

    /**
     * @return list<string>
     */
    public function listEnglishDomains(): array
    {
        $domains = [];
        foreach (glob($this->translationsDirectory.'/*.en.xlf') ?: [] as $path) {
            $domains[] = basename($path, '.en.xlf');
        }

        sort($domains);

        return $domains;
    }

    /**
     * @param list<string>|null $domains null = all EN domains
     *
     * @return list<array{
     *     domain: string,
     *     wave: string,
     *     missing: list<string>,
     *     existing: list<string>,
     * }>
     */
    public function build(?array $domains = null, ?string $waveFilter = null): array
    {
        $domains ??= $this->listEnglishDomains();
        $sections = [];

        foreach ($domains as $domain) {
            $englishPath = $this->translationsDirectory.'/'.$domain.'.en.xlf';
            $germanPath = $this->translationsDirectory.'/'.$domain.'.de.xlf';

            $englishKeys = $this->extractResnames($englishPath);
            $germanKeys = array_fill_keys($this->extractResnames($germanPath), true);

            $byWave = [];
            foreach (array_keys(self::WAVES) as $wave) {
                $byWave[$wave] = ['missing' => [], 'existing' => []];
            }
            $byWave['other'] = ['missing' => [], 'existing' => []];
            $byWave['no_de_file'] = ['missing' => [], 'existing' => []];

            $hasGermanFile = is_file($germanPath);

            foreach ($englishKeys as $key) {
                $wave = $this->resolveWave($key);
                if (!$hasGermanFile) {
                    $bucket = 'no_de_file';
                } elseif (isset($germanKeys[$key])) {
                    $bucket = $wave ?? 'other';
                    $byWave[$bucket]['existing'][] = $key;
                    continue;
                } else {
                    $bucket = $wave ?? 'other';
                }

                $byWave[$bucket]['missing'][] = $key;
            }

            foreach ($byWave as $wave => $counts) {
                if (null !== $waveFilter && $wave !== $waveFilter) {
                    continue;
                }

                if ([] === $counts['missing'] && [] === $counts['existing']) {
                    continue;
                }

                sort($counts['missing']);
                sort($counts['existing']);

                $sections[] = [
                    'domain' => $domain,
                    'wave' => $wave,
                    'missing' => $counts['missing'],
                    'existing' => $counts['existing'],
                ];
            }
        }

        return $sections;
    }

    /**
     * @param list<array{domain: string, wave: string, missing: list<string>, existing: list<string>}> $sections
     *
     * @return array{missing: int, existing: int, byWave: array<string, array{missing: int, existing: int}>}
     */
    public function summarize(array $sections): array
    {
        $totals = ['missing' => 0, 'existing' => 0, 'byWave' => []];

        foreach ($sections as $section) {
            $missing = \count($section['missing']);
            $existing = \count($section['existing']);
            $totals['missing'] += $missing;
            $totals['existing'] += $existing;

            $wave = $section['wave'];
            if (!isset($totals['byWave'][$wave])) {
                $totals['byWave'][$wave] = ['missing' => 0, 'existing' => 0];
            }
            $totals['byWave'][$wave]['missing'] += $missing;
            $totals['byWave'][$wave]['existing'] += $existing;
        }

        ksort($totals['byWave']);

        return $totals;
    }

    public function resolveWave(string $key): ?string
    {
        foreach (self::WAVES as $wave => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return $wave;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractResnames(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $xml = simplexml_load_file($path);
        if (false === $xml) {
            throw new \RuntimeException(sprintf('Unable to parse XLIFF file "%s".', $path));
        }

        $keys = [];
        foreach ($xml->file->body->{"trans-unit"} as $unit) {
            $resname = (string) $unit['resname'];
            if ('' !== $resname) {
                $keys[] = $resname;
            }
        }

        return $keys;
    }
}
