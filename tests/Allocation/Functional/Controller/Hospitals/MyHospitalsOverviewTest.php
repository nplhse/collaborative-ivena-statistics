<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Hospital cards and summary of My Hospitals (#971) per permission tier.
 */
#[ResetDatabase]
final class MyHospitalsOverviewTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use ClockSensitiveTrait;
    use Factories;

    private const string CARD = '[data-testid="my-hospitals-card"]';

    private KernelBrowser $client;

    private User $owner;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::mockTime(new \DateTimeImmutable('2026-03-20 10:00:00'));
        $this->owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'locale' => 'en']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();
    }

    public function testOwnerSeesEveryPartAndAction(): void
    {
        $hospital = $this->hospitalWithData('Data Hospital');

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(4, '[data-testid="my-hospitals-summary"] > div');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-allocations"]', '3');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary"]', 'from 1 hospital with data');
        // One completed and one failed import: two finished, half of them successful.
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-imports"]', '2');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-success-rate"]', '50 % successful');
        // January and February 2026 are fully covered; the rest of the window lies before the first import.
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-coverage"]', '2 of 11');

        self::assertSelectorTextContains(self::CARD, 'Owner');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-allocations"]', '3');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-period"]', 'Jan 15, 2026');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-period"]', 'Feb 28, 2026');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-coverage"]', '2 of 11');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-imports"]', '1 of 2 successful');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-imports"]', '(50%)');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-last-upload"]', 'Mar 10, 2026');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-own-uploads"]', '1');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-statistics"]', 'View statistics');
        self::assertSelectorExists('a[data-testid="my-hospitals-card-statistics"][href^="/statistics/?scope=hospital"][href$="'.$hospital->getId().'"]');
        self::assertSelectorExists('a[data-testid="my-hospitals-card-imports-link"][href="/import?hospitalId='.$hospital->getId().'"]');
        self::assertSelectorExists('a[data-testid="my-hospitals-card-upload"]');
        // Administrative actions sit in the card menu, not among the quick actions.
        self::assertSelectorExists('[data-testid="my-hospitals-card-menu"] + .dropdown-menu a[href="/hospitals/'.$hospital->getId().'/edit"]');
        self::assertSelectorExists('[data-testid="my-hospitals-card-menu"] + .dropdown-menu a[href="/hospitals/'.$hospital->getId().'/edit/access"]');
        self::assertSelectorNotExists('.card-footer a[href^="/hospitals/"]');
    }

    /**
     * @return iterable<string, array{list<HospitalPermission>, bool, bool}>
     */
    public static function permissionTiers(): iterable
    {
        yield 'view' => [[HospitalPermission::View], false, false];
        yield 'view and statistics' => [[HospitalPermission::View, HospitalPermission::Statistics], true, false];
        yield 'view and import' => [[HospitalPermission::View, HospitalPermission::Import], false, true];
    }

    /**
     * @param list<HospitalPermission> $permissions
     */
    #[DataProvider('permissionTiers')]
    public function testGrantHolderSeesOnlyPermittedParts(array $permissions, bool $statistics, bool $import): void
    {
        $hospital = $this->hospitalWithData('Shared Hospital');
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'locale' => 'en']);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions($permissions),
            'createdBy' => $this->owner,
        ]);

        $this->client->loginUser($grantee);
        $this->client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(self::CARD, 'Shared with you');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-allocations"]', '3');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-allocations"]', '3');

        $this->assertPresence('[data-testid="my-hospitals-card-coverage"]', $statistics);
        $this->assertPresence('[data-testid="my-hospitals-card-statistics"]', $statistics);
        $this->assertPresence('[data-testid="my-hospitals-summary-coverage"]', $statistics);

        $this->assertPresence('[data-testid="my-hospitals-card-imports"]', $import);
        $this->assertPresence('[data-testid="my-hospitals-card-own-uploads"]', $import);
        $this->assertPresence('[data-testid="my-hospitals-card-imports-link"]', $import);
        $this->assertPresence('[data-testid="my-hospitals-card-upload"]', $import);
        $this->assertPresence('[data-testid="my-hospitals-summary-imports"]', $import);

        self::assertSelectorNotExists('[data-testid="my-hospitals-card-menu"]');
        self::assertSelectorNotExists('a[href="/hospitals/'.$hospital->getId().'/edit"]');
        self::assertSelectorNotExists('a[href="/hospitals/'.$hospital->getId().'/edit/access"]');
        $this->assertPresence('[data-testid="my-hospitals-card"] .card-footer', $statistics || $import);
    }

    public function testSummaryStatesItsBasisWhenOnlySomeHospitalsAllowImports(): void
    {
        $this->hospitalWithData('Own Hospital');
        $shared = $this->hospitalWithData('Shared Hospital', UserFactory::createOne());
        HospitalAccessGrantFactory::createOne([
            'hospital' => $shared,
            'user' => $this->owner,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $this->owner,
        ]);

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-allocations"]', '6');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary"]', 'Based on 1 of 2 hospitals');
        self::assertSelectorCount(2, self::CARD);
    }

    public function testHospitalWithoutDataOffersTheUpload(): void
    {
        HospitalFactory::createOne(['name' => 'Empty Hospital', 'owner' => $this->owner, 'createdBy' => $this->owner]);

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-no-data"]', 'No allocations have been imported');
        self::assertSelectorExists('a.btn-primary[data-testid="my-hospitals-card-upload"]');
        self::assertSelectorNotExists('[data-testid="my-hospitals-card-period"]');
        self::assertSelectorNotExists('[data-testid="my-hospitals-card-statistics"]');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-period"]', 'No data yet');
    }

    public function testHospitalWithOnlyFailedImportsPointsToTheImports(): void
    {
        $hospital = HospitalFactory::createOne(['name' => 'Failed Hospital', 'owner' => $this->owner, 'createdBy' => $this->owner]);
        $this->import($hospital, ImportStatus::FAILED, '2026-03-01 10:00:00');

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-no-data"]', 'did not complete');
        self::assertSelectorTextContains('[data-testid="my-hospitals-card-imports"]', '0 of 1 successful');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-success-rate"]', '0 % successful');
    }

    public function testGermanPageHasNoMissingTranslations(): void
    {
        $german = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'locale' => 'de']);
        $this->hospitalWithData('Daten-Klinik', $german);

        $this->client->enableProfiler();
        $this->client->loginUser($german);
        $this->client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Meine Krankenhäuser');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-coverage"]', '2 von 11');
        self::assertSelectorTextContains('[data-testid="my-hospitals-summary-success-rate"]', '50 % erfolgreich');
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $this->assertNoMissingTranslations($profile);
    }

    private function hospitalWithData(string $name, ?User $owner = null): Hospital
    {
        $owner ??= $this->owner;
        $hospital = HospitalFactory::createOne(['name' => $name, 'owner' => $owner, 'createdBy' => $owner]);

        // Covers 1 January to 28 February 2026; a later import failed.
        $import = $this->import($hospital, ImportStatus::COMPLETED, '2026-03-10 10:00:00', $owner);
        $import->setExportStartsAt(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $import->setExportEndsAt(new \DateTimeImmutable('2026-02-28 23:59:00'));
        foreach (['2026-01-15 08:00:00', '2026-02-14 12:00:00', '2026-02-28 20:00:00'] as $createdAt) {
            AllocationFactory::createOne([
                'hospital' => $hospital,
                'import' => $import,
                'createdAt' => new \DateTimeImmutable($createdAt),
                'arrivalAt' => new \DateTimeImmutable($createdAt)->modify('+20 minutes'),
            ]);
        }
        $this->import($hospital, ImportStatus::FAILED, '2026-03-15 10:00:00', $owner);
        self::getContainer()->get('doctrine')->getManager()->flush();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport((int) $import->getId());

        return $hospital;
    }

    private function import(Hospital $hospital, ImportStatus $status, string $createdAt, ?User $uploader = null): Import
    {
        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $uploader ?? $this->owner,
            'status' => $status,
            'type' => ImportType::ALLOCATION,
            'createdAt' => new \DateTimeImmutable($createdAt),
        ]);
    }

    private function assertPresence(string $selector, bool $expected): void
    {
        if ($expected) {
            self::assertSelectorExists($selector);
        } else {
            self::assertSelectorNotExists($selector);
        }
    }
}
