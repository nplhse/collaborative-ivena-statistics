<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Functional\Controller;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EffectiveAssignments;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\User\Domain\Entity\User;
use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterMatch;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterTimes;
use Cois\Plugin\EdVisit\Domain\Entity\Triage;
use Cois\Plugin\EdVisit\Domain\Enum\ArrivalMode;
use Cois\Plugin\EdVisit\Domain\Enum\MatchStatus;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Lazily loaded triage in the column "Encounter" of the Explore allocation list (#1203). Hospital A grants the user
 * VIEW_CASES, hospital B does not; both have an ED visit linked to an allocation. Each encounter id is checked by
 * its own hospital, so asking for B's encounter returns nothing.
 */
#[ResetDatabase]
final class AllocationListTriageTest extends WebTestCase
{
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private const string DETAILS = '/explore/allocation/encounter-details';

    private KernelBrowser $client;

    private User $user;

    private int $encounterA;

    private int $encounterB;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->user = $this->user();
        $hospitalA = $this->hospital($this->user);
        $hospitalB = $this->hospital(null);
        $this->hospitalGrant($hospitalB, $this->user);
        $this->encounterGrant($hospitalA, $this->user, [EncounterGrantAction::ViewCases]);

        $this->encounterA = $this->linkedVisit($hospitalA, 'a');
        $this->encounterB = $this->linkedVisit($hospitalB, 'b');
    }

    public function testTheListRendersAPlaceholderPerLinkedEncounterOfVisibleHospitals(): void
    {
        $this->client->loginUser($this->user);

        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation?encounter=with');

        self::assertResponseIsSuccessful();
        $placeholders = $crawler->filter('[data-testid="allocation-list-encounter-detail"]');
        self::assertCount(1, $placeholders);
        self::assertSame((string) $this->encounterA, $placeholders->attr('data-encounter-list-detail-id-value'));
        self::assertSame(self::DETAILS, $placeholders->attr('data-encounter-list-detail-url-value'));
    }

    public function testDetailsAreReturnedOnlyForEncountersOfHospitalsWithCaseAccess(): void
    {
        $this->client->loginUser($this->user);

        $details = $this->details($this->encounterA.','.$this->encounterB);

        self::assertSame([$this->encounterA], array_keys($details));
        self::assertStringContainsString('data-testid="allocation-list-encounter-triage"', $details[$this->encounterA]);
        self::assertStringContainsString('badge bg-orange', $details[$this->encounterA]);
        // Codes resolve to display names through the MTS catalog, like on the visit list.
        self::assertStringContainsString('Chest pain', $details[$this->encounterA]);
        self::assertStringContainsString('Heiß', $details[$this->encounterA]);
        // Case data must not be stored by browsers or proxies.
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
    }

    public function testUsersWithoutCaseAccessGetNoDetails(): void
    {
        $other = $this->user();
        $this->hospitalGrant($this->hospitalOfEncounter($this->encounterA), $other);
        $this->client->loginUser($other);

        self::assertSame([], $this->details((string) $this->encounterA));
    }

    public function testInvalidIdsAreIgnored(): void
    {
        $this->client->loginUser($this->user);

        self::assertSame([], $this->details('abc,-1,0,999999'));
        self::assertSame([$this->encounterA], array_keys($this->details($this->encounterA.',x,'.$this->encounterA)));
    }

    public function testAnonymousUsersAreSentToTheLogin(): void
    {
        $this->client->request(Request::METHOD_GET, self::DETAILS.'?encounters='.$this->encounterA);

        self::assertResponseRedirects();
    }

    /**
     * @return array<int, string> html by encounter id (JSON object keys decode to integers)
     */
    private function details(string $ids): array
    {
        $this->client->request(Request::METHOD_GET, self::DETAILS.'?encounters='.$ids);
        self::assertResponseIsSuccessful();

        /** @var array{details: array<int, string>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['details'];
    }

    private function linkedVisit(Hospital $hospital, string $case): int
    {
        $visits = $this->encounterImport($hospital, 'Visits '.$case);
        $allocations = $this->encounterImport($hospital, 'Allocations '.$case, ImportType::ALLOCATION);
        $allocation = $this->allocationAt($hospital, $allocations, '10:00');

        $encounterId = (int) $this->encounterRepository()->insertIfNewReturningId(new EdVisit(
            hospital: $hospital,
            import: $visits,
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'list|'.$case),
            caseKey: hash('sha256', 'list|'.$case),
            age: 70,
            sex: Sex::Female,
            times: new EncounterTimes(edAdmissionAt: new \DateTimeImmutable('2026-10-12 10:07')),
            arrivalMode: ArrivalMode::Ambulance,
            triage: new Triage(MtsCategory::Orange, 'thoraxschmerz', 'heiss', 6),
        ));
        $this->pair($allocation, $encounterId, MatchStatus::AutoAccepted);

        return $encounterId;
    }

    private function pair(Allocation $allocation, int $encounterId, MatchStatus $status): void
    {
        $em = $this->encounterEntityManager();
        $encounter = $em->find(Encounter::class, $encounterId);
        $managed = $em->find(Allocation::class, $allocation->getId());
        self::assertInstanceOf(Encounter::class, $encounter);
        self::assertInstanceOf(Allocation::class, $managed);

        $em->persist(new EncounterMatch($managed, $encounter, $status, AnchorSource::Admission, 7, new \DateTimeImmutable('2026-10-12 11:00'), 0.9697, 9, [], 1, '1.0.0'));
        $em->flush();
        EffectiveAssignments::syncFromMatches($em->getConnection());
    }

    private function hospitalOfEncounter(int $encounterId): Hospital
    {
        $hospitalId = (int) $this->entityManager()->getConnection()->fetchOne('SELECT hospital_id FROM encounter WHERE id = ?', [$encounterId]);
        $hospital = $this->entityManager()->find(Hospital::class, $hospitalId);
        self::assertInstanceOf(Hospital::class, $hospital);

        return $hospital;
    }
}
