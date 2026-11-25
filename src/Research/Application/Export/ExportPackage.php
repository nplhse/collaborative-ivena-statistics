<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

use App\Research\Domain\Entity\DataRequestExportRun;
use App\Research\Domain\Entity\DataRequestQueryVersion;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The text files of an export archive (ADR 045): `manifest.json` for machines and a read-me in the recipient's
 * language with the request, the queries, the data state, the hospitals per file and a citation. Neither names a
 * person.
 */
final readonly class ExportPackage
{
    public const string SOURCE = 'Versorgungsforschung.io';

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public static function folder(DataRequestExportRun $run): string
    {
        $release = $run->getRelease();

        return \sprintf('data-request-%d-release-%d', (int) $release->getRequest()->getId(), $release->getNumber());
    }

    public static function readmeName(string $locale): string
    {
        return 'de' === $locale ? 'LIESMICH.txt' : 'README.txt';
    }

    /**
     * @param list<ExportFile>                                                       $files
     * @param array{latest_import_at: ?string, imports: int, latest_import_id: ?int} $dataState
     *
     * @return array<string, mixed>
     */
    public function manifest(DataRequestExportRun $run, array $files, array $dataState, \DateTimeImmutable $executedAt, string $requestUrl): array
    {
        $release = $run->getRelease();
        $request = $release->getRequest();
        $versions = $this->versionsByName($release->getQueryVersions());

        return [
            'source' => self::SOURCE,
            'request' => [
                'number' => $request->getId(),
                'url' => $requestUrl,
                'title' => ($request->getApprovedVersion() ?? $request->getCurrentVersion())->getTitle(),
                'version' => $request->getApprovedVersion()?->getNumber(),
            ],
            'release' => $release->getNumber(),
            'executed_at' => $executedAt->format(\DATE_ATOM),
            'data_as_of' => $dataState['latest_import_at'],
            'minimum_hospitals_per_group' => HospitalMinimum::MIN_HOSPITALS,
            'conditions' => $release->getConditions(),
            'files' => array_map(static function (ExportFile $file) use ($versions): array {
                $version = $versions[$file->name] ?? null;

                return [
                    'file' => $file->fileName(),
                    'description' => $version?->getQuery()->getDescription(),
                    'kind' => $version?->getQuery()->getKind()->value,
                    'query_version' => $version?->getNumber(),
                    'rows' => $file->rows,
                    'hospitals' => $file->hospitals,
                    'sha256' => $file->checksum,
                ];
            }, $files),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function readme(array $manifest, string $locale): string
    {
        /** @var array{number: int, url: string, title: string, version: ?int} $request */
        $request = $manifest['request'];
        /** @var list<array{file: string, description: ?string, rows: int, hospitals: int}> $files */
        $files = $manifest['files'];
        $executedAt = new \DateTimeImmutable((string) $manifest['executed_at']);
        $dataAsOf = \is_string($manifest['data_as_of']) ? $this->date(new \DateTimeImmutable($manifest['data_as_of']), $locale) : $this->trans('data_request.export.readme.no_data_state', [], $locale);

        $lines = [
            $this->trans('data_request.export.readme.heading', ['source' => self::SOURCE], $locale),
            '',
            $this->trans('data_request.export.readme.request', ['number' => $request['number'], 'title' => $request['title'], 'version' => $request['version'] ?? 1], $locale),
            $this->trans('data_request.export.readme.executed', ['date' => $this->date($executedAt, $locale)], $locale),
            $this->trans('data_request.export.readme.data_as_of', ['date' => $dataAsOf], $locale),
            '',
            $this->trans('data_request.export.readme.files', [], $locale),
        ];

        foreach ($files as $file) {
            $lines[] = '';
            $lines[] = $file['file'];
            $lines[] = '  '.$this->trans('data_request.export.readme.file_counts', ['rows' => $file['rows'], 'hospitals' => $file['hospitals']], $locale);
            foreach (explode("\n", trim((string) $file['description'])) as $line) {
                $lines[] = '  '.trim($line);
            }
        }

        if (\is_string($manifest['conditions'] ?? null)) {
            $lines[] = '';
            $lines[] = $this->trans('data_request.export.readme.conditions', [], $locale);
            foreach (explode("\n", $manifest['conditions']) as $line) {
                $lines[] = '  '.rtrim($line);
            }
        }

        $lines[] = '';
        $lines[] = $this->trans('data_request.export.readme.minimum', ['minimum' => HospitalMinimum::MIN_HOSPITALS], $locale);
        $lines[] = $this->trans('data_request.export.readme.format', [], $locale);
        $lines[] = '';
        $lines[] = $this->trans('data_request.export.readme.citation_heading', [], $locale);
        $lines[] = $this->trans('data_request.export.readme.citation', [
            'source' => self::SOURCE,
            'url' => $request['url'],
            'number' => $request['number'],
            'data_as_of' => $dataAsOf,
            'retrieved' => $this->date($executedAt, $locale),
        ], $locale);

        return implode("\n", $lines)."\n";
    }

    /**
     * @param list<DataRequestQueryVersion> $versions
     *
     * @return array<string, DataRequestQueryVersion>
     */
    private function versionsByName(array $versions): array
    {
        $byName = [];
        foreach ($versions as $version) {
            $byName[$version->getQuery()->getName()] = $version;
        }

        return $byName;
    }

    private function date(\DateTimeImmutable $date, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);

        return (string) $formatter->format($date);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function trans(string $key, array $parameters, string $locale): string
    {
        return $this->translator->trans($key, $parameters, 'research', $locale);
    }
}
