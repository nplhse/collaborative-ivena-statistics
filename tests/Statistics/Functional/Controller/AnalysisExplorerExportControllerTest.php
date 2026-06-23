<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalysisExplorerExportControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;

    public function testExportTableCsvReturnsStreamedCsv(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedProjectionWithAllocation();

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer?scope=public&period=all',
        );
        $this->assertResponseIsSuccessful();

        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        self::assertInstanceOf(ExplorerConfigMapper::class, $mapper);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        self::assertInstanceOf(DefaultAnalysisViewFactory::class, $viewFactory);

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $state = $mapper->toStateArray($viewFactory->createDefault($filter));
        $token = $this->csrfToken($client, 'explorer_export_csv');

        $client->request(
            Request::METHOD_POST,
            '/statistics/analysis/explorer/export/table.csv?scope=public&period=all',
            [
                '_token' => $token,
                'appliedConfigState' => json_encode($state, \JSON_THROW_ON_ERROR),
            ],
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');

        $disposition = $client->getResponse()->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('.csv', $disposition);
    }

    public function testExportRejectsInvalidCsrfToken(): void
    {
        $client = $this->createClientAsRoleUser();

        $client->request(
            Request::METHOD_POST,
            '/statistics/analysis/explorer/export/table.csv?scope=public&period=all',
            [
                '_token' => 'invalid-token',
                'appliedConfigState' => '{}',
            ],
        );

        self::assertResponseStatusCodeSame(400);
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $requestStack = $client->getContainer()->get('request_stack');
        $request = $client->getRequest();
        $requestStack->push($request);
        try {
            $token = $client->getContainer()->get('security.csrf.token_manager')->getToken($tokenId);
        } finally {
            $requestStack->pop();
        }

        return (string) $token->getValue();
    }

    private function seedProjectionWithAllocation(): void
    {
        $user = UserFactory::createOne(['username' => 'analysis-explorer-export-test']);
        $state = StateFactory::createOne(['name' => 'Explorer Export State', 'createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Explorer Export Dispatch']);
        $hospital = HospitalFactory::createOne(['name' => 'Explorer Export Hospital']);
        $import = ImportFactory::createOne(['name' => 'Explorer Export Import', 'hospital' => $hospital, 'createdBy' => $user]);
        SpecialityFactory::createOne(['name' => 'Explorer Export Speciality']);
        DepartmentFactory::createOne(['name' => 'Explorer Export Department']);
        AssignmentFactory::createOne(['name' => 'Explorer Export Assignment']);
        OccasionFactory::createOne(['name' => 'Explorer Export Occasion']);
        InfectionFactory::createOne(['name' => 'Explorer Export Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Explorer Export Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Explorer Export Normalized']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);

        $this->rebuildProjectionForImports([(int) $import->getId()]);
    }
}
