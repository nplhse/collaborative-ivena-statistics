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
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A hospital breakdown names each hospital with its figures, so only admins get it.
 */
#[ResetDatabase]
final class AnalysisExplorerHospitalDimensionAccessTest extends WebTestCase
{
    use Factories;
    use RefreshesStatisticsFunctionalDataTrait;

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function roles(): iterable
    {
        yield 'user without participant role' => [[UserRole::USER], false];
        yield 'participant' => [[UserRole::USER, UserRole::PARTICIPANT], false];
        yield 'admin' => [[UserRole::USER, UserRole::ADMIN], true];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('roles')]
    public function testStateScopeHospitalRowsAreAdminOnly(array $roles, bool $expectsHospitalRows): void
    {
        $client = self::createClient();
        $stateId = $this->seedStateWithTwoHospitals();

        $client->loginUser(UserFactory::createOne(['roles' => $roles]));
        $client->request(Request::METHOD_GET, '/statistics/analysis/explorer?scope=public&period=all');
        self::assertResponseIsSuccessful();

        $csv = $this->exportHospitalRowsForState($client, $stateId);

        if ($expectsHospitalRows) {
            self::assertStringContainsString('"Hospital A",3', $csv);
            self::assertStringContainsString('"Hospital B",5', $csv);
        } else {
            self::assertStringNotContainsString('Hospital A', $csv);
            self::assertStringNotContainsString('Hospital B', $csv);
        }

        // Both cases keep the state total of 3 + 5 allocations.
        self::assertMatchesRegularExpression('/^Total,8$/m', $csv);
    }

    private function seedStateWithTwoHospitals(): int
    {
        $owner = UserFactory::createOne();
        $state = StateFactory::createOne(['name' => 'Test State', 'createdBy' => $owner]);
        $area = DispatchAreaFactory::createOne(['name' => 'Test Area']);
        $raw = IndicationRawFactory::createOne();
        $normalized = IndicationNormalizedFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();
        InfectionFactory::createOne();

        $importIds = [];
        foreach (['Hospital A' => 3, 'Hospital B' => 5] as $name => $count) {
            $hospital = HospitalFactory::createOne(['name' => $name, 'state' => $state, 'dispatchArea' => $area]);
            $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $owner]);
            $importIds[] = (int) $import->getId();
            for ($i = 0; $i < $count; ++$i) {
                AllocationFactory::createOne([
                    'createdAt' => new \DateTimeImmutable(\sprintf('2024-06-1%d 10:00:00', $i)),
                    'import' => $import,
                    'hospital' => $hospital,
                    'state' => $state,
                    'dispatchArea' => $area,
                    'indicationRaw' => $raw,
                    'indicationNormalized' => $normalized,
                ]);
            }
        }

        $this->rebuildProjectionForImports($importIds);
        $this->refreshOverviewMaterializedViews();

        return (int) $state->getId();
    }

    private function exportHospitalRowsForState(KernelBrowser $client, int $stateId): string
    {
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        self::assertInstanceOf(ExplorerConfigMapper::class, $mapper);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        self::assertInstanceOf(DefaultAnalysisViewFactory::class, $viewFactory);

        $state = $mapper->toStateArray($viewFactory->createDefault(new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::AllTime,
        )));
        $state['query']['scope'] = ['group' => 'state', 'detail' => (string) $stateId];
        $state['query']['rows'] = ['dimension' => 'hospital', 'grain' => 'total'];
        $state['query']['columns'] = null;

        $client->request(Request::METHOD_POST, '/statistics/analysis/explorer/export/table.csv?scope=public&period=all', [
            '_token' => $this->csrfToken($client),
            'appliedConfigState' => json_encode($state, \JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseIsSuccessful();

        return (string) $client->getInternalResponse()->getContent();
    }

    private function csrfToken(KernelBrowser $client): string
    {
        $requestStack = $client->getContainer()->get('request_stack');
        $requestStack->push($client->getRequest());

        try {
            return (string) $client->getContainer()->get('security.csrf.token_manager')->getToken('explorer_export_csv')->getValue();
        } finally {
            $requestStack->pop();
        }
    }
}
