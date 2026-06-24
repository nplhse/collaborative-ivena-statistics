<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
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
}
