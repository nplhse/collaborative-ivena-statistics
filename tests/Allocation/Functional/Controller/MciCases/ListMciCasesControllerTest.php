<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\MciCases;

use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\MciCaseFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListMciCasesControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    private const string SHARED_MCI_ID = 'evt-shared-42';

    private const string OTHER_MCI_ID = 'evt-other-99';

    public function testListShowsEveryMciCaseWithoutAFilter(): void
    {
        $client = $this->createClientAsParticipant();
        $this->createSharedAndOtherCases();

        $client->request(Request::METHOD_GET, '/explore/mci_case');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Hospital Alpha');
        self::assertSelectorTextContains('table', 'Hospital Beta');
        self::assertSelectorTextContains('table', 'Hospital Gamma');
        self::assertSelectorNotExists('[data-testid="mci-case-filters-active"]');
        self::assertSelectorTextContains('[data-testid="mci-case-filters-drawer-trigger"]', 'Filters');
        self::assertSelectorExists('a[href="/explore/mci_case?mciId='.rawurlencode(self::SHARED_MCI_ID).'"]');
        self::assertSelectorExists('a[href="/explore/mci_case?mciId='.rawurlencode(self::OTHER_MCI_ID).'"]');
    }

    public function testMciIdFilterReturnsMatchingCasesAcrossHospitals(): void
    {
        $client = $this->createClientAsParticipant();
        $this->createSharedAndOtherCases();

        $client->request(Request::METHOD_GET, '/explore/mci_case?mciId='.rawurlencode(self::SHARED_MCI_ID));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Hospital Alpha');
        self::assertSelectorTextContains('table', 'Hospital Beta');
        self::assertSelectorTextNotContains('table', 'Hospital Gamma');
        self::assertSelectorTextContains('[data-testid="mci-case-filters-active"]', 'Active filters');
        self::assertSelectorTextContains('[data-testid="mci-case-filters-active"]', 'MCI ID');
        self::assertSelectorTextContains('[data-testid="mci-case-filters-active"]', self::SHARED_MCI_ID);
        self::assertSelectorExists('[data-testid="mci-case-filters-clear"]');
        self::assertSelectorExists('#mci-case-filters input[type="hidden"][name="mciId"][value="'.self::SHARED_MCI_ID.'"]');
        self::assertSelectorNotExists('select[name="importId"]');
        self::assertSelectorExists('input[name="search"]');
    }

    public function testHospitalFilterLimitsTheListToThatClinic(): void
    {
        $client = $this->createClientAsParticipant();
        $alpha = $this->createMciCase(self::SHARED_MCI_ID, 'Bridge collapse west', 'Hospital Alpha');
        $this->createMciCase(self::SHARED_MCI_ID, 'Bridge collapse east', 'Hospital Beta');
        $hospital = $alpha->getHospital();
        self::assertNotNull($hospital);

        $client->request(Request::METHOD_GET, '/explore/mci_case?hospital='.$hospital->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Hospital Alpha');
        self::assertSelectorTextNotContains('table', 'Hospital Beta');
        self::assertSelectorTextContains('[data-testid="mci-case-filters-active"]', 'Hospital Alpha');
        self::assertSelectorExists('select[name="hospital"]');
    }

    public function testDispatchAreaFilterLimitsTheList(): void
    {
        $client = $this->createClientAsParticipant();
        $alpha = $this->createMciCase(self::SHARED_MCI_ID, 'Bridge collapse west', 'Hospital Alpha');
        $this->createMciCase(self::OTHER_MCI_ID, 'Unrelated incident', 'Hospital Gamma');
        $dispatchArea = $alpha->getDispatchArea();
        self::assertNotNull($dispatchArea);

        $client->request(Request::METHOD_GET, '/explore/mci_case?dispatchArea='.$dispatchArea->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Hospital Alpha');
        self::assertSelectorTextNotContains('table', 'Hospital Gamma');
    }

    public function testImportFilterShowsTheImportNameWithoutASelect(): void
    {
        $client = $this->createClientAsParticipant();
        $alpha = $this->createMciCase(self::SHARED_MCI_ID, 'Bridge collapse west', 'Hospital Alpha', 'Night import');
        $this->createMciCase(self::OTHER_MCI_ID, 'Unrelated incident', 'Hospital Gamma', 'Other import');
        $import = $alpha->getImport();
        self::assertNotNull($import);

        $client->request(Request::METHOD_GET, '/explore/mci_case?importId='.$import->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Hospital Alpha');
        self::assertSelectorTextNotContains('table', 'Hospital Gamma');
        self::assertSelectorTextContains('[data-testid="mci-case-filters-active"]', 'Night import');
        self::assertSelectorNotExists('select[name="importId"]');
        self::assertSelectorExists('#mci-case-filters input[type="hidden"][name="importId"][value="'.$import->getId().'"]');
    }

    public function testArrivalDateFilterLimitsTheList(): void
    {
        $client = $this->createClientAsParticipant();
        $this->createMciCase(
            self::SHARED_MCI_ID,
            'Bridge collapse west',
            'Hospital Alpha',
            arrivalAt: new \DateTimeImmutable('2024-01-15 08:00:00'),
        );
        $this->createMciCase(
            self::OTHER_MCI_ID,
            'Unrelated incident',
            'Hospital Gamma',
            arrivalAt: new \DateTimeImmutable('2024-06-01 08:00:00'),
        );

        $client->request(Request::METHOD_GET, '/explore/mci_case?arrivalFrom=2024-01-01&arrivalUntil=2024-01-31');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Hospital Alpha');
        self::assertSelectorTextNotContains('table', 'Hospital Gamma');
        self::assertSelectorTextContains('[data-testid="mci-case-filters-active"]', '01.01.2024');
        self::assertSelectorTextContains('[data-testid="mci-case-filters-active"]', '31.01.2024');
    }

    public function testSearchMatchesMciIdSubstring(): void
    {
        $client = $this->createClientAsParticipant();
        $this->createSharedAndOtherCases();

        $client->request(Request::METHOD_GET, '/explore/mci_case?search=shared-42');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Hospital Alpha');
        self::assertSelectorTextContains('table', 'Hospital Beta');
        self::assertSelectorTextNotContains('table', 'Hospital Gamma');
        self::assertSelectorTextContains('[data-testid="mci-case-filters-active"]', 'Search');
        self::assertSelectorTextContains('[data-testid="mci-case-filters-active"]', 'shared-42');
    }

    public function testSearchMatchesMciTitle(): void
    {
        $client = $this->createClientAsParticipant();
        $this->createSharedAndOtherCases();

        $client->request(Request::METHOD_GET, '/explore/mci_case?search='.rawurlencode('collapse west'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Hospital Alpha');
        self::assertSelectorTextNotContains('table', 'Hospital Beta');
        self::assertSelectorTextNotContains('table', 'Hospital Gamma');
    }

    public function testEmptySearchOffersResetWithoutASeparateSearchAlert(): void
    {
        $client = $this->createClientAsParticipant();
        $this->createSharedAndOtherCases();

        $client->request(Request::METHOD_GET, '/explore/mci_case?search=nothing-matches-this');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="mci-case-filters-active"]', 'nothing-matches-this');
        self::assertSelectorTextContains('.empty-title', 'No results for the current filters');
        self::assertSelectorExists('.empty-action a[href="/explore/mci_case"]:not([target])');
        self::assertStringNotContainsString('Searching for:', (string) $client->getResponse()->getContent());
    }

    private function createSharedAndOtherCases(): void
    {
        $this->createMciCase(self::SHARED_MCI_ID, 'Bridge collapse west', 'Hospital Alpha');
        $this->createMciCase(self::SHARED_MCI_ID, 'Bridge collapse east', 'Hospital Beta');
        $this->createMciCase(self::OTHER_MCI_ID, 'Unrelated incident', 'Hospital Gamma');
    }

    private function createMciCase(
        string $mciId,
        string $title,
        string $hospitalName,
        ?string $importName = null,
        ?\DateTimeImmutable $arrivalAt = null,
    ): MciCase {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => $hospitalName,
            'owner' => $user,
            'createdBy' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
        $importAttributes = [
            'hospital' => $hospital,
            'createdBy' => $user,
        ];
        if (null !== $importName) {
            $importAttributes['name'] = $importName;
        }
        $import = ImportFactory::createOne($importAttributes);

        $attributes = [
            'mciId' => $mciId,
            'mciTitle' => $title,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'import' => $import,
            'hospital' => $hospital,
            'occasion' => null,
            'speciality' => null,
            'department' => null,
            'infection' => null,
            'indicationRaw' => null,
            'indicationNormalized' => null,
        ];
        if ($arrivalAt instanceof \DateTimeImmutable) {
            $attributes['arrivalAt'] = $arrivalAt;
        }

        return MciCaseFactory::createOne($attributes);
    }
}
