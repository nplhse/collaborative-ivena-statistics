<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\Explore\Catalog\CatalogGlossaryFactory;
use App\Allocation\Application\Explore\Catalog\CatalogGlossarySlug;
use PHPUnit\Framework\TestCase;

final class CatalogGlossaryFactoryTest extends TestCase
{
    public function testUrgencyGlossaryContainsSkCodesAndAllocationFilters(): void
    {
        $page = new CatalogGlossaryFactory()->create(CatalogGlossarySlug::Urgency);

        self::assertSame(CatalogGlossarySlug::Urgency, $page->slug);
        self::assertCount(1, $page->sections);
        self::assertCount(3, $page->sections[0]->terms);

        $emergency = $page->sections[0]->terms[0];
        self::assertSame('SK1', $emergency->code);
        self::assertSame('app_explore_allocation_list', $emergency->filterRoute);
        self::assertSame(['urgency' => '1'], $emergency->filterParams);
    }

    public function testHospitalClassificationsLinkToHospitalList(): void
    {
        $page = new CatalogGlossaryFactory()->create(CatalogGlossarySlug::HospitalClassifications);

        self::assertCount(3, $page->sections);
        $basic = $page->sections[0]->terms[0];
        self::assertSame('Basic', $basic->code);
        self::assertSame('app_explore_hospital_list', $basic->filterRoute);
        self::assertSame('catalog.action.view_hospitals', $basic->actionLabelKey);
    }

    public function testClinicalIndicatorsIncludeResourcesAndFeatures(): void
    {
        $page = new CatalogGlossaryFactory()->create(CatalogGlossarySlug::ClinicalIndicators);

        self::assertCount(2, $page->sections);
        self::assertSame('resus', $page->sections[0]->terms[0]->code);
        self::assertSame(['requiresResus' => 1], $page->sections[0]->terms[0]->filterParams);

        $withPhysician = null;
        foreach ($page->sections[1]->terms as $term) {
            if ('with_physician' === $term->code) {
                $withPhysician = $term;
                break;
            }
        }

        self::assertNotNull($withPhysician);
        self::assertFalse($withPhysician->hasFilter());
    }

    public function testIndexEntriesCoverAllSlugs(): void
    {
        $entries = new CatalogGlossaryFactory()->indexEntries();

        self::assertCount(4, $entries);
        self::assertSame(CatalogGlossarySlug::Urgency, $entries[0]['slug']);
    }
}
