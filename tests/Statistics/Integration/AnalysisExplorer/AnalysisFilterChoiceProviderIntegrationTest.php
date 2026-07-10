<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Statistics\AnalysisExplorer\Application\AnalysisFilterChoiceProvider;
use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalysisFilterChoiceProviderIntegrationTest extends KernelTestCase
{
    use Factories;

    private AnalysisFilterChoiceProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->provider = self::getContainer()->get(AnalysisFilterChoiceProvider::class);
    }

    public function testEntityChoiceMethodsReturnSeededLabels(): void
    {
        UserFactory::createOne();
        $department = DepartmentFactory::createOne(['name' => 'Coverage Department']);
        $speciality = SpecialityFactory::createOne(['name' => 'Coverage Speciality']);
        $assignment = AssignmentFactory::createOne(['name' => 'Coverage Assignment']);

        $departmentId = $department->getId();
        $specialityId = $speciality->getId();
        $assignmentId = $assignment->getId();
        self::assertNotNull($departmentId);
        self::assertNotNull($specialityId);
        self::assertNotNull($assignmentId);

        self::assertSame('Coverage Department', $this->provider->departmentChoices()[$departmentId]);
        self::assertSame('Coverage Speciality', $this->provider->specialityChoices()[$specialityId]);
        self::assertSame('Coverage Assignment', $this->provider->assignmentChoices()[$assignmentId]);
    }

    public function testEnumBackedChoicesExposeTranslatedLabels(): void
    {
        $urgencyChoices = $this->provider->urgencyChoices();
        $transportChoices = $this->provider->transportTypeChoices();
        $genderChoices = $this->provider->genderChoices();

        self::assertNotEmpty($urgencyChoices);
        self::assertNotEmpty($transportChoices);
        self::assertNotEmpty($genderChoices);
        self::assertContainsOnly('string', $urgencyChoices);
        self::assertNotContains('U1', $urgencyChoices);
        self::assertContains('Emergency Care', $urgencyChoices);
    }

    public function testAgeGroupChoicesIncludeAggregatesAndDecadeBuckets(): void
    {
        $choices = $this->provider->ageGroupChoices();

        self::assertArrayHasKey(StatisticsAgeGroupFilter::UNDER_18, $choices);
        self::assertArrayHasKey(StatisticsAgeGroupFilter::OVER_80, $choices);
        self::assertArrayHasKey('30_39', $choices);
        self::assertArrayNotHasKey('unknown', $choices);
    }

    public function testBooleanTriStateChoices(): void
    {
        $choices = $this->provider->booleanTriStateChoices();

        self::assertArrayHasKey('', $choices);
        self::assertArrayHasKey(1, $choices);
        self::assertArrayHasKey(0, $choices);
    }

    public function testIndicationChoicesUseNameAndCodeLabel(): void
    {
        UserFactory::createOne();
        $indication = IndicationNormalizedFactory::createOne([
            'name' => 'STEMI',
            'code' => 42,
        ]);
        $indicationId = $indication->getId();
        self::assertNotNull($indicationId);

        self::assertSame('STEMI (42)', $this->provider->indicationChoices()[$indicationId]);
    }

    public function testIndicationGroupChoicesReturnSeededLabels(): void
    {
        UserFactory::createOne();
        $group = IndicationGroupFactory::createOne(['name' => 'Cardiac group']);
        $groupId = $group->getId();
        self::assertNotNull($groupId);

        self::assertSame('Cardiac group', $this->provider->indicationGroupChoices()[$groupId]);
    }

    public function testSecondEntityChoiceCallUsesCachedReferenceData(): void
    {
        UserFactory::createOne();
        DepartmentFactory::createOne(['name' => 'Cached Department']);

        $first = $this->provider->departmentChoices();
        DepartmentFactory::createOne(['name' => 'New Department']);
        $second = $this->provider->departmentChoices();

        self::assertSame($first, $second);
        self::assertCount(1, $second);
        self::assertSame('Cached Department', $second[array_key_first($second)]);
    }
}
