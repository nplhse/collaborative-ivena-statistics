<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\AllocationList;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Filter "Associated encounter" and column "Encounter" of the Explore allocation list (#1203). Access is decided
 * per hospital (ADR 028): a grant for one hospital never reveals encounter data of another.
 *
 * Fixture: hospital A (VIEW_CASES for the user) with allocation A1 linked to an encounter and A2 without;
 * hospital B (hospital access, no encounter grant) with B1 linked and B2 without.
 */
#[ResetDatabase]
final class EncounterAllocationListTest extends WebTestCase
{
    use EncounterTestFixtures;
    use Factories;

    private KernelBrowser $client;

    /** @var array<string, Allocation> */
    private array $allocations = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        UserFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();
        SecondaryTransportFactory::createOne();
        InfectionFactory::createOne();
        IndicationRawFactory::createOne();
        IndicationNormalizedFactory::createOne();
    }

    public function testWithShowsOnlyLinkedAllocationsOfHospitalsWithCaseAccessAndTheirColumn(): void
    {
        $this->loginAs($this->participantWithCaseAccessToA());

        $crawler = $this->list('?encounter=with');

        self::assertSame([$this->publicId('A1')], $this->listedIds($crawler));
        $links = $crawler->filter('[data-testid="allocation-list-encounter-link"]');
        self::assertCount(1, $links);
        self::assertStringContainsString('#encounter-hospital-course', (string) $links->attr('href'));
        self::assertSelectorExists('[data-testid="allocation-filter-encounter"] option[value="with"][selected]');
    }

    public function testWithoutKeepsOnlyUnlinkedAllocationsOfHospitalsWithCaseAccess(): void
    {
        $this->loginAs($this->participantWithCaseAccessToA());

        // B2 has no encounter either, but whether hospital B has one is not the user's to know.
        self::assertSame([$this->publicId('A2')], $this->listedIds($this->list('?encounter=without')));
    }

    public function testThePickedColumnIsEmptyForHospitalsWithoutCaseAccess(): void
    {
        $this->loginAs($this->participantWithCaseAccessToA());

        $crawler = $this->list('?columns=timestamps,encounter');

        self::assertCount(4, $this->listedIds($crawler));
        self::assertSame(['A1'], $this->rowsWith($crawler, '[data-testid="allocation-list-encounter-link"]'));
        self::assertSame(['A2'], $this->rowsWith($crawler, '[data-testid="allocation-list-encounter-none"]'));
    }

    public function testUsersWithoutCaseAccessSeeNeitherFilterNorColumnAndTheParameterIsIgnored(): void
    {
        $this->participantWithCaseAccessToA();
        $user = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA']);
        $this->hospitalGrant($this->hospitalOf('A1'), $user);
        $this->loginAs($user);

        $crawler = $this->list('?encounter=with&columns=timestamps,encounter');

        self::assertCount(4, $this->listedIds($crawler));
        self::assertSelectorNotExists('[data-testid="allocation-filter-encounter"]');
        self::assertCount(0, $crawler->filter('[data-testid^="allocation-list-encounter-"]'));
    }

    public function testACaseGrantWithoutTheBetaRoleGivesNoAccess(): void
    {
        $this->participantWithCaseAccessToA();
        $user = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $this->hospitalGrant($this->hospitalOf('A1'), $user);
        $this->encounterGrant($this->hospitalOf('A1'), $user, [EncounterGrantAction::ViewCases]);
        $this->loginAs($user);

        self::assertCount(4, $this->listedIds($this->list('?encounter=with')));
        self::assertSelectorNotExists('[data-testid="allocation-filter-encounter"]');
    }

    public function testAdministratorsSeeEveryHospital(): void
    {
        $this->participantWithCaseAccessToA();
        $this->loginAs($this->user(['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ADMIN']));

        $ids = $this->listedIds($this->list('?encounter=with'));
        sort($ids);
        $expected = [$this->publicId('A1'), $this->publicId('B1')];
        sort($expected);

        self::assertSame($expected, $ids);
    }

    public function testTheActiveFilterHasABadgeAndCountsAsActive(): void
    {
        $this->loginAs($this->participantWithCaseAccessToA());

        $this->list('?encounter=without');

        self::assertSelectorTextContains('.alert .badge', 'Associated encounter: Without associated encounter');
    }

    private function participantWithCaseAccessToA(): User
    {
        $user = $this->user();
        $hospitalA = $this->hospital($user);
        $hospitalB = $this->hospital(null);
        $this->hospitalGrant($hospitalB, $user);
        $this->encounterGrant($hospitalA, $user, [EncounterGrantAction::ViewCases]);

        foreach (['A' => $hospitalA, 'B' => $hospitalB] as $prefix => $hospital) {
            $import = ImportFactory::createOne(['hospital' => $hospital]);
            foreach ([1, 2] as $number) {
                $this->allocations[$prefix.$number] = AllocationFactory::createOne(['hospital' => $hospital, 'import' => $import]);
            }
            $this->encounterFor($hospital, $this->allocations[$prefix.'1']);
        }

        return $user;
    }

    private function encounterFor(Hospital $hospital, Allocation $allocation): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "INSERT INTO encounter (hospital_id, key_generation, case_key, anchor_at, age, sex, created_at, allocation_id) VALUES (?, 1, ?, '2026-10-12 12:00:00', 40, 'F', '2026-10-12 12:00:00', ?)",
            [$hospital->getId(), hash('sha256', 'case-'.$allocation->getId()), $allocation->getId()],
        );
    }

    private function hospitalOf(string $label): Hospital
    {
        $hospital = $this->allocations[$label]->getHospital();
        self::assertInstanceOf(Hospital::class, $hospital);

        return $hospital;
    }

    private function publicId(string $label): string
    {
        return $this->allocations[$label]->getPublicIdString();
    }

    private function loginAs(User $user): void
    {
        $this->client->loginUser($user);
    }

    private function list(string $query): Crawler
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation'.$query.(str_contains($query, '?') ? '&' : '?').'limit=50');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return list<string>
     */
    private function listedIds(Crawler $crawler): array
    {
        $ids = [];
        foreach ($crawler->filter('td .btn-actions a') as $node) {
            if ($node instanceof \DOMElement && 1 === preg_match('#/explore/allocation/([0-9a-f-]{36})$#', $node->getAttribute('href'), $matches)) {
                $ids[] = $matches[1];
            }
        }

        return $ids;
    }

    /**
     * Labels of the fixture rows that contain an element matching the selector.
     *
     * @return list<string>
     */
    private function rowsWith(Crawler $crawler, string $selector): array
    {
        $labels = [];
        foreach ($crawler->filter('tbody tr') as $row) {
            $rowCrawler = new Crawler($row);
            if (0 === $rowCrawler->filter($selector)->count()) {
                continue;
            }
            foreach ($this->allocations as $label => $allocation) {
                if (str_contains($rowCrawler->html(), $allocation->getPublicIdString())) {
                    $labels[] = $label;
                }
            }
        }
        sort($labels);

        return $labels;
    }
}
