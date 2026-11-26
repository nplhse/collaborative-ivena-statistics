<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Curation;

use Cois\Plugin\EdVisit\Application\Contract\CurationStateReaderInterface;
use Cois\Plugin\EdVisit\Application\Curation\Package\AliasData;
use Cois\Plugin\EdVisit\Application\Curation\Package\AreaRuleData;
use Cois\Plugin\EdVisit\Application\Curation\Package\AssignmentData;
use Cois\Plugin\EdVisit\Application\Curation\Package\CatalogEntryData;
use Cois\Plugin\EdVisit\Application\Curation\Package\CurationPackage;
use Cois\Plugin\EdVisit\Application\Curation\Package\CurationPackageFormat;
use Cois\Plugin\EdVisit\Application\Curation\Package\CurationPackageWriter;
use Cois\Plugin\EdVisit\Application\Curation\Package\PackageChecksum;
use Cois\Plugin\EdVisit\Application\Curation\Package\PackageMeta;
use Cois\Plugin\EdVisit\Application\Curation\Package\ProblemAreaData;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogKind;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogStatus;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the curation package of this instance (#1198): curated diagrams and indicators, aliases to curated entries
 * (a former merge travels as its alias), assignments between curated entries, and all problem areas with their PZC
 * codes and the rules on curated diagrams.
 *
 * Never exported: provisional and merged entries, snapshots, cells, counts, learning data, first-seen timestamps,
 * ids, users, and anything tied to hospitals, allocations or visits.
 */
final readonly class CurationExporter
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private CurationStateReaderInterface $reader,
        private CurationPackageWriter $writer,
        private ClockInterface $clock,
        #[Autowire(param: 'app.version')]
        private string $appVersion,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    public function export(?string $notes = null): CurationExport
    {
        $state = $this->reader->state();
        $omitted = ['entries' => 0, 'aliases' => 0, 'assignments' => 0, 'rules' => 0];

        $entries = [];
        foreach ([MtsCatalogKind::Diagram->value, MtsCatalogKind::Indicator->value] as $kind) {
            $entries[$kind] = [];
            foreach ($state->entries[$kind] ?? [] as $code => $entry) {
                if (MtsCatalogStatus::Curated !== $entry['status']) {
                    // Merged entries travel as their alias; only provisional ones are left out.
                    $omitted['entries'] += MtsCatalogStatus::Provisional === $entry['status'] ? 1 : 0;
                    continue;
                }

                $entries[$kind][] = new CatalogEntryData((string) $code, $entry['german_name'], $entry['english_name'], $entry['source']);
            }
        }

        $aliases = [];
        foreach ($state->aliases as $kind => $byCode) {
            foreach ($byCode as $code => $alias) {
                if (!$state->isCurated($kind, $alias['target_code'])) {
                    ++$omitted['aliases'];
                    continue;
                }

                $aliases[] = new AliasData($kind, (string) $code, $alias['label'], $alias['target_code']);
            }
        }

        usort($aliases, static fn (AliasData $a, AliasData $b): int => [$a->kind, $a->code] <=> [$b->kind, $b->code]);

        $assignments = [];
        foreach ($state->assignments as $diagram => $indicators) {
            foreach ($indicators as $indicator) {
                if (!$state->isCurated(MtsCatalogKind::Diagram->value, (string) $diagram) || !$state->isCurated(MtsCatalogKind::Indicator->value, $indicator)) {
                    ++$omitted['assignments'];
                    continue;
                }

                $assignments[] = new AssignmentData((string) $diagram, $indicator);
            }
        }

        usort($assignments, static fn (AssignmentData $a, AssignmentData $b): int => [$a->diagram, $a->indicator] <=> [$b->diagram, $b->indicator]);

        $areas = [];
        foreach ($state->areas as $code => $area) {
            $rules = [];
            foreach ($area['rules'] as $diagram => $points) {
                if (!$state->isCurated(MtsCatalogKind::Diagram->value, (string) $diagram)) {
                    ++$omitted['rules'];
                    continue;
                }

                $rules[(string) $diagram] = new AreaRuleData((string) $diagram, $points);
            }

            ksort($rules, \SORT_STRING);
            $pzc = $area['pzc'];
            sort($pzc);
            $areas[(string) $code] = new ProblemAreaData((string) $code, $area['german_name'], $area['english_name'], $pzc, array_values($rules));
        }

        ksort($areas, \SORT_STRING);

        $package = new CurationPackage(
            new PackageMeta(
                CurationPackageFormat::NAME,
                CurationPackageFormat::VERSION,
                $this->clock->now()->format(\DateTimeInterface::ATOM),
                [],
                new PackageChecksum(CurationPackageFormat::CHECKSUM_ALGORITHM, str_repeat('0', 64)),
                $this->appVersion,
                $this->environment,
                $notes,
            ),
            $this->sortByCode($entries[MtsCatalogKind::Diagram->value]),
            $this->sortByCode($entries[MtsCatalogKind::Indicator->value]),
            $aliases,
            $assignments,
            array_values($areas),
        );

        return new CurationExport($this->writer->seal($package), $omitted);
    }

    /**
     * @param list<CatalogEntryData> $entries
     *
     * @return list<CatalogEntryData>
     */
    private function sortByCode(array $entries): array
    {
        usort($entries, static fn (CatalogEntryData $a, CatalogEntryData $b): int => strcmp($a->code, $b->code));

        return $entries;
    }
}
