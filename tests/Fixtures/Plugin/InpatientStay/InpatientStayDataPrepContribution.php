<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay;

use App\Encounter\Contract\DataPrep\DataPrepContributionInterface;
use App\Encounter\Contract\DataPrep\DataPrepLocalMap;

/**
 * The test schema in the data preparation tool (#1301): schema file and one source profile, nothing else. No script
 * module, no catalog, no local map: the generic core of the tool handles it alone. Paths from the bundle directory.
 */
final readonly class InpatientStayDataPrepContribution implements DataPrepContributionInterface
{
    #[\Override]
    public function schema(): string
    {
        return InpatientStayDimension::SCHEMA;
    }

    #[\Override]
    public function schemaFile(): string
    {
        return __DIR__.'/data-prep/inpatient_stay.v1.json';
    }

    #[\Override]
    public function profileFiles(): array
    {
        return [__DIR__.'/data-prep/profiles/inpatient-poc.json'];
    }

    #[\Override]
    public function scriptFile(): ?string
    {
        return null;
    }

    #[\Override]
    public function catalogs(): array
    {
        return [];
    }

    #[\Override]
    public function localMap(): ?DataPrepLocalMap
    {
        return null;
    }
}
