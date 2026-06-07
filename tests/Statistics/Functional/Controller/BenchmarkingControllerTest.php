<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BenchmarkingControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testRedirectsToPublicScopesOnFirstVisitWithoutHospitalAccess(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'benchmark-ctrl-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user->_real());

        $client->request(Request::METHOD_GET, '/statistics/benchmarking');

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('comparison_scope=public', $location);
        self::assertStringContainsString('comparison_period=all_time', $location);
        self::assertStringContainsString('scope=public', $location);
        self::assertStringContainsString('period=all', $location);
    }

    public function testRedirectsToMyHospitalsAndHospitalCohortForParticipantOnFirstVisit(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'username' => 'benchmark-participant-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $state = StateFactory::createOne(['name' => 'BenchmarkDefaultState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'BenchmarkDefaultDispatch', 'state' => $state]);
        HospitalFactory::createOne([
            'name' => 'BenchmarkDefaultHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
        ]);
        $client->loginUser($user->_real());

        $client->request(Request::METHOD_GET, '/statistics/benchmarking');

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('scope=my_hospitals', $location);
        self::assertStringContainsString('comparison_scope=hospital_cohort', $location);
        self::assertStringContainsString('comparison_period=all_time', $location);
        self::assertStringContainsString('period=all', $location);
    }

    public function testRendersBenchmarkingDashboard(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'benchmark-render-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user->_real());

        $state = StateFactory::createOne(['name' => 'BenchmarkRenderState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'BenchmarkRenderDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'BenchmarkRenderHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'BenchmarkRenderSpec']);
        DepartmentFactory::createOne(['name' => 'BenchmarkRenderDept']);
        AssignmentFactory::createOne(['name' => 'BenchmarkRenderAssign']);
        IndicationRawFactory::createOne(['name' => 'BenchmarkRenderRaw', 'code' => 912_401]);

        $import = ImportFactory::createOne(['name' => 'BenchmarkRenderImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 70,
            'isWithPhysician' => true,
            'createdAt' => new \DateTimeImmutable('2026-03-01 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 12:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(Request::METHOD_GET, '/statistics/benchmarking', [
            'scope' => 'public',
            'period' => 'all',
            'comparison_scope' => 'public',
            'comparison_period' => 'all_time',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-benchmark-heading"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-kpi-table"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-indication-mix"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-indication-mix-table"]');
        self::assertSelectorNotExists('[data-testid="stats-benchmark-indication-mix-over-expand"]');
        self::assertSelectorNotExists('[data-testid="stats-benchmark-indication-mix-under-expand"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-selection-card"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-primary-scope"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-comparison-scope"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-primary-cases"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-comparison-cases"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-demographics-age"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-demographics-gender"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-urgency"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-resources"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-clinical-features"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-transport-type"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-transport-times"]');
        self::assertSelectorNotExists('[data-testid="stats-benchmark-transport-mode"]');
        self::assertSelectorNotExists('[data-testid="stats-benchmark-weekdays"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-case-distribution"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-case-distribution"] [data-benchmarking-charts-target="heatmapModeDayTime"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-case-distribution"] [data-benchmarking-charts-target="heatmapModeShift"]');
        self::assertSelectorExists('[data-testid="stats-benchmark-case-distribution-help"]');
    }
}
