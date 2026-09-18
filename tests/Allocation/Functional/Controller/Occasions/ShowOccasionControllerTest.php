<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Occasions;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowOccasionControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testDetailPageShowsOccasionCatalogModules(): void
    {
        $client = $this->createClientAsAreaUser();
        $occasion = OccasionFactory::createOne(['name' => 'Primary emergency']);

        $crawler = $client->request(Request::METHOD_GET, '/explore/occasion/'.$occasion->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#occasion-name', 'Primary emergency');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-actions"]');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('occasion='.$occasion->getId(), $href);

        $actionHrefs = $crawler->filter('[data-testid="catalog-action"]')->each(
            static fn ($node): string => (string) $node->attr('href'),
        );
        self::assertContains('/statistics/top-lists/top_occasions', $actionHrefs);
    }

    public function testYearHeatmapLinksToFilteredAllocationList(): void
    {
        $client = $this->createClientAsAreaUser();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Year Hospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'name' => 'Year Import']);
        $occasion = OccasionFactory::createOne(['name' => 'Domestic emergency']);
        AssignmentFactory::createOne(['name' => 'Year Assignment']);
        DepartmentFactory::createOne(['name' => 'Year Department']);
        SpecialityFactory::createOne(['name' => 'Year Speciality']);
        IndicationRawFactory::createOne(['name' => 'Year Indication']);
        IndicationNormalizedFactory::createOne(['name' => 'Year Indication']);

        for ($i = 0; $i < 5; ++$i) {
            AllocationFactory::createOne([
                'hospital' => $hospital,
                'import' => $import,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'occasion' => $occasion,
                'createdAt' => new \DateTimeImmutable('2024-06-15 10:00:00'),
                'arrivalAt' => new \DateTimeImmutable('2024-06-15 10:20:00'),
            ]);
        }

        $importId = $import->getId();
        self::assertNotNull($importId);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)
            ->rebuildForImport($importId);

        $crawler = $client->request(Request::METHOD_GET, '/explore/occasion/'.$occasion->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="catalog-coverage-year-link"]');

        $href = $crawler->filter('[data-testid="catalog-coverage-year-link"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('occasion='.$occasion->getId(), $href);
        self::assertStringContainsString('createdFrom=2024-01-01T00:00:00', $href);
        self::assertStringContainsString('createdToExclusive=2025-01-01T00:00:00', $href);
    }
}
