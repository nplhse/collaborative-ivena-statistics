<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\MciCases;

use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\MciCaseFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowMciCaseControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testDetailPageShowsMciCase(): void
    {
        $client = $this->createClientAsParticipant();
        $mciCase = $this->createMciCase('Mass casualty incident alpha');
        $client->request(Request::METHOD_GET, '/explore/mci_case/'.$mciCase->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.fw-bold', 'Mass casualty incident alpha');
        self::assertSelectorTextContains('#mci-case-mci-title', $mciCase->getMciId() ?? '');
        self::assertSelectorTextContains('a.btn', 'Back to list');

        $hospital = $mciCase->getHospital();
        $dispatchArea = $mciCase->getDispatchArea();
        $state = $mciCase->getState();
        $department = $mciCase->getDepartment();
        $speciality = $mciCase->getSpeciality();
        $indicationNormalized = $mciCase->getIndicationNormalized();
        $infection = $mciCase->getInfection();
        self::assertNotNull($hospital);
        self::assertNotNull($dispatchArea);
        self::assertNotNull($state);
        self::assertNotNull($department);
        self::assertNotNull($speciality);
        self::assertNotNull($indicationNormalized);
        self::assertNotNull($infection);
        self::assertSelectorExists('a[href="/explore/hospital/'.$hospital->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/dispatch_area/'.$dispatchArea->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/state/'.$state->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/department/'.$department->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/speciality/'.$speciality->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/indication/'.$indicationNormalized->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/infection/'.$infection->getPublicIdString().'"]');
        self::assertSelectorNotExists('a[href*="/explore/indication/raw/"]');
    }

    public function testDetailPageRejectsPostMethod(): void
    {
        $client = $this->createClientAsParticipant();
        $mciCase = $this->createMciCase();
        $client->request(Request::METHOD_POST, '/explore/mci_case/'.$mciCase->getPublicIdString());

        self::assertResponseStatusCodeSame(405);
    }

    private function createMciCase(string $title = 'Test MCI'): MciCase
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $user,
            'createdBy' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        return MciCaseFactory::createOne([
            'mciTitle' => $title,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'import' => $import,
            'hospital' => $hospital,
            'occasion' => OccasionFactory::createOne(),
            'speciality' => SpecialityFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'infection' => InfectionFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(),
        ]);
    }
}
