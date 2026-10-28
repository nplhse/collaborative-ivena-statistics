<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\Allocation\Application\ReferenceCatalog\Package\PackageChecksum;
use App\Allocation\Application\ReferenceCatalog\Package\PackageMeta;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogFormat;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;

/**
 * Combines the synthetic geography and hospitals with the catalog of another package (ADR 020).
 *
 * States, dispatch areas and hospitals come from the synthetic package, so hospital identities and
 * locations stay synthetic and the maps know every area. Departments, specialities, lookups,
 * indications and groups come from the given package, for example a private catalog, so real files
 * and the generator resolve against it. The result is a complete package and goes through the same
 * validation and import as any other.
 */
final readonly class ComposedReferenceCatalog
{
    /** Types taken from the given package; it must contain all of them. */
    public const array CATALOG_TYPES = [
        ReferenceCatalogType::Department,
        ReferenceCatalogType::Speciality,
        ReferenceCatalogType::Assignment,
        ReferenceCatalogType::Occasion,
        ReferenceCatalogType::Infection,
        ReferenceCatalogType::SecondaryTransport,
        ReferenceCatalogType::IndicationNormalized,
        ReferenceCatalogType::IndicationRaw,
        ReferenceCatalogType::IndicationGroup,
    ];

    public function __construct(
        private ReferenceCatalogPackageWriter $writer,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the catalog package lacks a type it has to provide
     */
    public function compose(ReferenceCatalogPackage $synthetic, ReferenceCatalogPackage $catalog): ReferenceCatalogPackage
    {
        $missing = array_values(array_filter(
            self::CATALOG_TYPES,
            static fn (ReferenceCatalogType $type): bool => !\in_array($type->value, $catalog->meta->types, true),
        ));
        if ([] !== $missing) {
            throw new \InvalidArgumentException(sprintf('The reference package lacks the types %s; export it completely or with --types=%s.', implode(', ', array_map(static fn (ReferenceCatalogType $type): string => $type->value, $missing)), implode(',', array_map(static fn (ReferenceCatalogType $type): string => $type->value, self::CATALOG_TYPES))));
        }

        return $this->writer->seal(new ReferenceCatalogPackage(
            new PackageMeta(
                ReferenceCatalogFormat::NAME,
                ReferenceCatalogFormat::VERSION,
                $synthetic->meta->exportedAt,
                true,
                ReferenceCatalogType::values(),
                [],
                new PackageChecksum(ReferenceCatalogFormat::CHECKSUM_ALGORITHM, ''),
                null,
                'composed',
                array_merge($synthetic->meta->sources, $catalog->meta->sources),
                'Synthetic geography and hospitals with the catalog of another package.',
            ),
            $synthetic->states,
            $synthetic->dispatchAreas,
            $catalog->departments,
            $catalog->specialities,
            $catalog->assignments,
            $catalog->occasions,
            $catalog->infections,
            $catalog->secondaryTransports,
            $catalog->indicationsNormalized,
            $catalog->indicationsRaw,
            $catalog->indicationGroups,
            $synthetic->hospitals,
        ));
    }
}
