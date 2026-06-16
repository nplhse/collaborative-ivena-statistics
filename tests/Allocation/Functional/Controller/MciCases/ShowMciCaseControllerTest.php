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
        $id = $mciCase->getId();
        self::assertNotNull($id);

        $client->request(Request::METHOD_GET, '/explore/mci_case/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.fw-bold', 'Mass casualty incident alpha');
        self::assertSelectorTextContains('#mci-case-mci-title', $mciCase->getMciId() ?? '');
        self::assertSelectorTextContains('a.btn-outline-secondary', 'Back to list');
    }

    public function testDetailPageRejectsPostMethod(): void
    {
        $client = $this->createClientAsParticipant();
        $mciCase = $this->createMciCase();
        $id = $mciCase->getId();
        self::assertNotNull($id);

        $client->request(Request::METHOD_POST, '/explore/mci_case/'.$id);

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
        ])->_real();
    }
}
