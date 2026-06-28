<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Export;

use App\Allocation\Application\Export\ExportHospitalFormOptionsProvider;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExportHospitalFormOptionsProviderTest extends KernelTestCase
{
    use Factories;

    private ExportHospitalFormOptionsProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->provider = self::getContainer()->get(ExportHospitalFormOptionsProvider::class);
    }

    public function testSingleHospitalOwnerGetsVisibleChoicesAndDefaultSelection(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Solo Hospital']);

        $options = $this->provider->optionsFor($owner, 'en');
        $formOptions = $this->provider->formOptionsFor($owner, 'en');

        self::assertCount(1, $options['hospital_choices']);
        self::assertSame([(int) $hospital->getId()], $options['default_hospital_ids']);
        self::assertArrayNotHasKey('default_hospital_ids', $formOptions);
        self::assertSame('', $options['hospitals_help']);
        self::assertSame('My hospitals', $options['hospitals_section_label']);
    }

    public function testAdminGetsHospitalsLabelAndHelpForMultipleChoices(): void
    {
        $admin = UserFactory::createOne(['roles' => [UserRole::ADMIN, 'ROLE_USER']]);
        HospitalFactory::createOne(['name' => 'Hospital A']);
        HospitalFactory::createOne(['name' => 'Hospital B']);

        $options = $this->provider->optionsFor($admin, 'en');

        self::assertGreaterThanOrEqual(2, \count($options['hospital_choices']));
        self::assertNotSame('', $options['hospitals_help']);
        self::assertSame('Hospitals', $options['hospitals_section_label']);
    }
}
