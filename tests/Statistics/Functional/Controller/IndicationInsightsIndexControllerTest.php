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
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class IndicationInsightsIndexControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testIndexRendersTopDiagnosesAndPicker(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'indication-index-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user->_real());

        $state = StateFactory::createOne(['name' => 'IndicationIndexState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'IndicationIndexDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'IndicationIndexHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'IndicationIndexSpec']);
        DepartmentFactory::createOne(['name' => 'IndicationIndexDept']);
        AssignmentFactory::createOne(['name' => 'IndicationIndexAssign']);
        IndicationRawFactory::createOne(['name' => 'IndicationIndexRaw', 'code' => 912_351]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Index Test Indication', 'code' => 1002]);

        $import = ImportFactory::createOne(['name' => 'IndicationIndexImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 42,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-04-01 14:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 14:25:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication-insights', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="stats-indication-insights-title"]');
        self::assertSelectorExists('[data-testid="stats-indication-insights-top-table"]');
        self::assertSelectorExists('[data-testid="stats-indication-picker"]');
        self::assertSelectorExists('#stats-indication-picker-input');
        self::assertSelectorNotExists('[data-testid="stats-indication-insights-top-table"] .card-subtitle');
        self::assertSelectorTextContains('[data-testid="stats-indication-insights-top-table"]', 'Index Test Indication');
        self::assertSelectorExists(
            sprintf('a[href*="/statistics/indication/%d"]', $indication->getId()),
        );
    }
}
