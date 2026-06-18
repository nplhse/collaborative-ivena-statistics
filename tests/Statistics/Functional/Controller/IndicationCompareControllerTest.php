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
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationCompareControllerTest extends WebTestCase
{
    use Factories;

    public function testCompareRendersKpiTable(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CompareState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CompareDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CompareHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CompareSpec']);
        DepartmentFactory::createOne(['name' => 'CompareDept']);
        AssignmentFactory::createOne(['name' => 'CompareAssign']);
        IndicationRawFactory::createOne(['name' => 'CompareRaw', 'code' => 912_361]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Compare Indication A', 'code' => 2001]);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Compare Indication B', 'code' => 2002]);

        $import = ImportFactory::createOne(['name' => 'CompareImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:20:00'),
        ]);

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-05-02 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-02 11:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'indication_a' => (string) $indicationA->getId(),
            'indication_b' => (string) $indicationB->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-indication-compare-kpi-tiles"]');
        self::assertSelectorExists('[data-testid="stats-indication-compare-edit-button"]');
        self::assertSelectorExists('[data-testid="stats-indication-compare-edit-modal"]');
        $crawler = $client->getCrawler();
        self::assertStringContainsString(
            '/statistics/indication/'.$indicationA->getId(),
            (string) $crawler->filter('[data-testid="stats-indication-compare-label-a"]')->attr('href'),
        );
        self::assertStringContainsString(
            '/statistics/indication/'.$indicationB->getId(),
            (string) $crawler->filter('[data-testid="stats-indication-compare-label-b"]')->attr('href'),
        );
        self::assertSelectorExists('[data-testid="stats-indication-compare-case-distribution"]');
        self::assertSelectorExists('[data-benchmarking-charts-target="caseDistributionHeatmap"]');
        self::assertSelectorTextContains('[data-testid="stats-indication-compare-label-a"]', 'Compare Indication A');
        self::assertSelectorTextContains('[data-testid="stats-indication-compare-label-b"]', 'Compare Indication B');
        self::assertSelectorExists('[data-testid="stats-indication-compare-gender"]');
        self::assertSelectorExists('[data-testid="stats-indication-compare-urgency"]');
        self::assertSelectorTextContains('[data-testid="stats-indication-compare-urgency"]', 'Emergency');
        self::assertSelectorTextContains('[data-testid="stats-indication-compare-urgency"]', 'Inpatient');
        self::assertSelectorNotExists('[data-testid="stats-indication-compare-insights"]');
    }

    public function testCompareRendersInsightsWhenSampleLargeEnough(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-insights-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CompareInsightsState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CompareInsightsDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CompareInsightsHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CompareInsightsSpec']);
        DepartmentFactory::createOne(['name' => 'CompareInsightsDept']);
        AssignmentFactory::createOne(['name' => 'CompareInsightsAssign']);
        IndicationRawFactory::createOne(['name' => 'CompareInsightsRaw', 'code' => 912_364]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Insights Indication A', 'code' => 3001]);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Insights Indication B', 'code' => 3002]);

        $import = ImportFactory::createOne(['name' => 'CompareInsightsImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(40, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'isWithPhysician' => true,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:20:00'),
        ]);

        AllocationFactory::createMany(40, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'isWithPhysician' => false,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-05-02 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-02 11:20:00'),
        ]);

        AllocationFactory::createMany(10, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'isWithPhysician' => true,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-05-03 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-03 12:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication/compare', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'indication_a' => (string) $indicationA->getId(),
            'indication_b' => (string) $indicationB->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-indication-compare-insights"]');
        self::assertSelectorExists('[data-testid="stats-indication-compare-insight-physician"]');
    }

    public function testRedirectsWhenSameIndicationSelected(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-compare-same-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $indication = IndicationNormalizedFactory::createOne(['name' => 'Same Indication']);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication/compare', [
            'scope' => 'public',
            'period' => 'all',
            'indication_a' => (string) $indication->getId(),
            'indication_b' => (string) $indication->getId(),
        ]);

        self::assertResponseRedirects();
    }
}
