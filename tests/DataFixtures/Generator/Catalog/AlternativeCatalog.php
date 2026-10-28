<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Catalog;

use App\Allocation\Application\ReferenceCatalog\Package\AliasedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\AliasEntry;
use App\Allocation\Application\ReferenceCatalog\Package\IndicationGroupEntry;
use App\Allocation\Application\ReferenceCatalog\Package\NamedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\NormalizedIndicationEntry;
use App\Allocation\Application\ReferenceCatalog\Package\PackageChecksum;
use App\Allocation\Application\ReferenceCatalog\Package\PackageMeta;
use App\Allocation\Application\ReferenceCatalog\Package\RawIndicationEntry;
use App\Allocation\Application\ReferenceCatalog\Package\RawIndicationReview;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogFormat;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\DataFixtures\Generator\Application\Catalog\CatalogMapping;
use App\DataFixtures\Generator\Application\Catalog\GeneratorKeys;
use App\DataFixtures\Reference\ComposedReferenceCatalog;
use Symfony\Component\Uid\Uuid;

/**
 * A second synthetic catalog that a private one could look like: other labels, other public ids,
 * another insert order and two normalized indications for one code. Every change is listed here,
 * so the expected results of the tests can be followed by hand.
 *
 * Only the catalog types are included; geography and hospitals come from the synthetic package when
 * it is composed (`ComposedReferenceCatalog`).
 */
final readonly class AlternativeCatalog
{
    /** Departments renamed without the old name as alias: only the mapping file can bind them. */
    public const array RENAMED_DEPARTMENTS = [
        'Allgemeinchirurgie' => 'Chirurgie allgemein',
        'Geburtshilfe' => 'Kreißsaal',
        'Schlaganfallmedizin' => 'Stroke Unit',
    ];

    /** Departments renamed with the old name kept as alias: they resolve like in the import. */
    public const array ALIASED_DEPARTMENTS = [
        'Pulmologie' => 'Pneumologie',
    ];

    public const array RENAMED_SPECIALITIES = [
        'Pädiatrie' => 'Kinder- und Jugendmedizin',
    ];

    public const array RENAMED_ASSIGNMENTS = [
        'RD' => 'Rettungsdienst',
    ];

    /** The stroke unit is written with internal medicine instead of its speciality key (neurology). */
    public const array SPECIALITY_OF_DEPARTMENT = [
        'stroke-unit' => 'Innere Medizin',
    ];

    /**
     * A second normalized indication for 322 whose name sorts before the official one
     * ("Synkope / Kollaps"); the generator takes it by name, whatever the database ids.
     */
    public const string SECOND_CODE = '322';

    public const string SECOND_NAME = 'Kollaps unklarer Genese';

    /** Public ids are new: a UUIDv5 of the synthetic id in this namespace. */
    private const string ID_NAMESPACE = '0c7d1b9e-3f5a-4e2b-8a61-9d4c2f7e1b30';

    public function __construct(
        private ReferenceCatalogPackageWriter $writer,
    ) {
    }

    /**
     * @param bool $reversed insert every section in reverse order, so the rows get other database ids
     */
    public function from(ReferenceCatalogPackage $synthetic, bool $reversed = false): ReferenceCatalogPackage
    {
        $order = static fn (array $entries): array => $reversed ? array_reverse($entries) : $entries;

        $normalized = array_map(fn (NormalizedIndicationEntry $entry): NormalizedIndicationEntry => new NormalizedIndicationEntry($this->id($entry->id), $entry->code, $entry->name, $entry->note), $synthetic->indicationsNormalized);
        $second = new NormalizedIndicationEntry($this->id('second-'.self::SECOND_CODE), self::SECOND_CODE, self::SECOND_NAME);
        $normalized[] = $second;

        $raw = array_map(fn (RawIndicationEntry $entry): RawIndicationEntry => new RawIndicationEntry(
            $this->id($entry->id),
            $entry->code,
            $entry->name,
            $entry->review,
            null !== $entry->target ? $this->id($entry->target) : null,
            $entry->firstMatchedAt,
        ), $synthetic->indicationsRaw);
        $raw[] = new RawIndicationEntry($this->id('second-raw-'.self::SECOND_CODE), self::SECOND_CODE, self::SECOND_NAME, new RawIndicationReview('matched', null, '2026-01-01T00:00:00+00:00'), $second->id);

        return $this->writer->seal(new ReferenceCatalogPackage(
            new PackageMeta(
                ReferenceCatalogFormat::NAME,
                ReferenceCatalogFormat::VERSION,
                $synthetic->meta->exportedAt,
                false,
                array_map(static fn (\App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType $type): string => $type->value, ComposedReferenceCatalog::CATALOG_TYPES),
                [],
                new PackageChecksum(ReferenceCatalogFormat::CHECKSUM_ALGORITHM, ''),
                null,
                'alternative',
                [],
                'Second synthetic catalog for the tests of #875.',
            ),
            [],
            [],
            $order($this->departments($synthetic->departments)),
            $order($this->renamed($synthetic->specialities, self::RENAMED_SPECIALITIES)),
            $order($this->named($synthetic->assignments, self::RENAMED_ASSIGNMENTS)),
            $order($this->named($synthetic->occasions)),
            $order($this->named($synthetic->infections)),
            $order($this->named($synthetic->secondaryTransports)),
            $order($normalized),
            $order($raw),
            $order(array_map(fn (IndicationGroupEntry $group): IndicationGroupEntry => new IndicationGroupEntry(
                $this->id($group->id),
                $group->name,
                array_map($this->id(...), $group->members),
                $group->category,
                $group->description,
                $group->sortOrder,
            ), $synthetic->indicationGroups)),
            [],
        ));
    }

    /**
     * The mapping that makes this catalog serve the generator, keyed by generator keys.
     */
    public static function mapping(): CatalogMapping
    {
        $byKey = static function (array $renamed, callable $key): array {
            $mapped = [];
            foreach ($renamed as $label => $name) {
                $mapped[$key($label)] = $name;
            }

            return $mapped;
        };

        return new CatalogMapping(
            names: [
                'department' => $byKey(self::RENAMED_DEPARTMENTS, GeneratorKeys::department(...)),
                'speciality' => $byKey(self::RENAMED_SPECIALITIES, GeneratorKeys::speciality(...)),
                'assignment' => self::RENAMED_ASSIGNMENTS,
            ],
            specialityOfDepartment: self::SPECIALITY_OF_DEPARTMENT,
            source: 'alternative catalog mapping',
        );
    }

    public static function mappingJson(): string
    {
        return json_encode([
            'format' => CatalogMapping::FORMAT,
            'version' => CatalogMapping::VERSION,
            'names' => self::mapping()->names,
            'speciality_of_department' => self::SPECIALITY_OF_DEPARTMENT,
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<AliasedEntry> $departments
     *
     * @return list<AliasedEntry>
     */
    private function departments(array $departments): array
    {
        $entries = [];
        foreach ($this->renamed($departments, self::RENAMED_DEPARTMENTS + self::ALIASED_DEPARTMENTS) as $department) {
            $old = array_search($department->name, self::ALIASED_DEPARTMENTS, true);
            if (\is_string($old)) {
                $department = new AliasedEntry($department->id, $department->name, [...$department->aliases, new AliasEntry($old, 'unambiguous_alias', 'synthetic')]);
            }
            $entries[] = $department;
        }

        return $entries;
    }

    /**
     * @param list<AliasedEntry>    $entries
     * @param array<string, string> $names   old → new
     *
     * @return list<AliasedEntry>
     */
    private function renamed(array $entries, array $names): array
    {
        return array_map(fn (AliasedEntry $entry): AliasedEntry => new AliasedEntry($this->id($entry->id), $names[$entry->name] ?? $entry->name, $entry->aliases), $entries);
    }

    /**
     * @param list<NamedEntry>      $entries
     * @param array<string, string> $names   old → new
     *
     * @return list<NamedEntry>
     */
    private function named(array $entries, array $names = []): array
    {
        return array_map(fn (NamedEntry $entry): NamedEntry => new NamedEntry($this->id($entry->id), $names[$entry->name] ?? $entry->name), $entries);
    }

    private function id(string $id): string
    {
        return Uuid::v5(Uuid::fromString(self::ID_NAMESPACE), $id)->toRfc4122();
    }
}
