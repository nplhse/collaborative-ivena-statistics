<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Export;

use App\Allocation\Application\Export\OwnHospitalAllocationsExporter;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Shared\Application\Export\ExportBlockedException;
use App\Shared\Application\Export\ExportEstimate;
use App\Shared\Application\Export\ExportLimits;
use App\Shared\Application\Export\ExportOrchestrator;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AllocationsExportControllerTest extends WebTestCase
{
    use Factories;

    public function testNonParticipantCannotAccessExportPage(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, '/hospitals/export/allocations');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testParticipantWithoutExportScopeCannotAccessExportPage(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'participant-no-hospital']);
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, '/hospitals/export/allocations');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testOwnerCanOpenExportPageAndSeesNavLink(): void
    {
        [$client] = $this->createOwnerClient();

        $client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('a[href="/hospitals/export/allocations"]', 'Export');

        $client->request(Request::METHOD_GET, '/hospitals/export/allocations');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="export-allocations-prepare"]');
        self::assertSelectorExists('[data-testid="export-filter-section-hospital"]');
        self::assertSelectorExists('input[type="checkbox"][name*="[hospitals]"]:checked');
    }

    public function testPrepareExportDoesNotDuplicateFilterFieldsInEstimateFrame(): void
    {
        [$client] = $this->createOwnerClient();

        $this->submitEstimate($client);

        self::assertSelectorExists('[data-testid="export-estimate-ok"]');
        self::assertSelectorExists('[data-testid="export-allocations-download"]');
        self::assertSelectorNotExists('#export-estimate select[name*="[urgency]"]');
        self::assertSelectorNotExists('#export-estimate #export-allocations-download-form');
    }

    public function testParticipantWithoutHospitalDoesNotSeeNavLink(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'nav-no-export']);
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/hospitals/export/allocations"]'));
    }

    public function testOwnerExportDownloadIsSuccessful(): void
    {
        [$client, $owner] = $this->createOwnerClient();
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'other-owner']);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $ownedHospital = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Owned Hospital Export']);
        $foreignHospital = HospitalFactory::createOne(['owner' => $other, 'name' => 'Foreign Hospital Export']);
        $this->seedAllocationDependencies($ownedHospital);

        AllocationFactory::createOne([
            'hospital' => $ownedHospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-15 10:00:00'),
            'createdAt' => new \DateTimeImmutable('2026-01-15 09:00:00'),
        ]);
        AllocationFactory::createOne([
            'hospital' => $foreignHospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-15 11:00:00'),
            'createdAt' => new \DateTimeImmutable('2026-01-15 09:30:00'),
        ]);

        $formValues = $this->submitEstimate($client);
        self::assertSelectorExists('[data-testid="export-estimate-ok"]');

        $this->submitDownload($client, $formValues);
        self::assertResponseIsSuccessful();
        self::assertSame('text/csv; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testGrantUserWithExportPermissionCanExportGrantHospital(): void
    {
        $client = self::createClient();

        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'grant-export-user']);
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'grant-owner']);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Grant Hospital Export']);
        $this->seedAllocationDependencies($hospital);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([
                HospitalPermission::View,
                HospitalPermission::Export,
            ]),
        ]);

        AllocationFactory::createOne([
            'hospital' => $hospital,
            'arrivalAt' => new \DateTimeImmutable('2026-01-20 12:00:00'),
        ]);

        $client->loginUser($grantee);

        $formValues = $this->submitEstimate($client);
        self::assertSelectorExists('[data-testid="export-estimate-ok"]');

        $this->submitDownload($client, $formValues);
        self::assertResponseIsSuccessful();
        self::assertSame('text/csv; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testEstimateShowsWarningForLargeExport(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->mockOrchestratorEstimate(new ExportEstimate(
            ExportLimits::WARN_EXPORT_ROWS,
            false,
            true,
            OwnHospitalAllocationsExporter::KEY,
        ));
        $this->loginOwnerOnClient($client);

        $this->submitEstimate($client);

        self::assertSelectorExists('[data-testid="export-estimate-warning"]');
        self::assertSelectorExists('[data-testid="export-allocations-download"]');
    }

    public function testEstimateBlocksWhenTooManyRows(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->mockOrchestratorEstimate(new ExportEstimate(
            ExportLimits::MAX_EXPORT_ROWS + 1,
            true,
            false,
            OwnHospitalAllocationsExporter::KEY,
        ));
        $this->loginOwnerOnClient($client);

        $this->submitEstimate($client);

        self::assertSelectorExists('[data-testid="export-estimate-blocked"]');
        self::assertSelectorNotExists('[data-testid="export-allocations-download"]');
    }

    public function testDownloadRevalidatesRowLimit(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $mock = $this->createMock(ExportOrchestrator::class);
        $mock->method('estimate')->willReturn(new ExportEstimate(100, false, false, OwnHospitalAllocationsExporter::KEY));
        $mock->method('download')->willThrowException(new ExportBlockedException(ExportLimits::MAX_EXPORT_ROWS + 1, OwnHospitalAllocationsExporter::KEY));
        self::getContainer()->set(ExportOrchestrator::class, $mock);

        $this->loginOwnerOnClient($client);

        $formValues = $this->submitEstimate($client);
        $this->submitDownload($client, $formValues);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testNavExportAppearsBetweenImportAndBlog(): void
    {
        [$client] = $this->createOwnerClient();

        $crawler = $client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        $navItems = $crawler->filter('#navbar-menu .nav-item .nav-link');
        $labels = [];
        foreach ($navItems as $item) {
            $labels[] = trim(preg_replace('/\s+/', ' ', $item->textContent ?? '') ?? '');
        }

        $importIndex = array_search('Import', $labels, true);
        $exportIndex = array_search('Export', $labels, true);
        $blogIndex = array_search('Blog', $labels, true);

        self::assertNotFalse($importIndex);
        self::assertNotFalse($exportIndex);
        self::assertNotFalse($blogIndex);
        self::assertSame($importIndex + 1, $exportIndex);
        self::assertSame($exportIndex + 1, $blogIndex);
    }

    /**
     * @return array{0: KernelBrowser, 1: User}
     */
    private function createOwnerClient(): array
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'export-owner-'.bin2hex(random_bytes(4))]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        $this->seedAllocationDependencies($hospital);
        $client->loginUser($owner);

        return [$client, $owner];
    }

    private function loginOwnerOnClient(KernelBrowser $client): User
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'export-owner-'.bin2hex(random_bytes(4))]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        $this->seedAllocationDependencies($hospital);
        $client->loginUser($owner);

        return $owner;
    }

    private function seedAllocationDependencies(object $hospital): void
    {
        ImportFactory::createOne(['name' => 'Export Test Import', 'hospital' => $hospital]);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        IndicationRawFactory::createOne(['name' => 'Test Indication Raw']);
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
    }

    /**
     * @return array<string, mixed>
     */
    private function submitEstimate(KernelBrowser $client): array
    {
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/export/allocations');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#export-allocations-form')->form();
        $form['own_hospital_allocations_export[dateFrom]'] = '2026-01-01';
        $form['own_hospital_allocations_export[dateTo]'] = '2026-01-31';
        $values = $form->getPhpValues();

        $client->request(
            Request::METHOD_POST,
            '/hospitals/export/allocations/estimate',
            $values,
            [],
            ['HTTP_TURBO_FRAME' => 'export-estimate'],
        );
        self::assertResponseIsSuccessful();

        return $values;
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function submitDownload(KernelBrowser $client, array $formValues): void
    {
        $formValues['_token'] = (string) $client->getCrawler()
            ->filter('input[name="_token"]')
            ->attr('value');

        $client->request(
            Request::METHOD_POST,
            '/hospitals/export/allocations/download',
            $formValues,
        );
    }

    private function mockOrchestratorEstimate(ExportEstimate $estimate): void
    {
        $mock = $this->createMock(ExportOrchestrator::class);
        $mock->method('estimate')->willReturn($estimate);
        self::getContainer()->set(ExportOrchestrator::class, $mock);
    }
}
