<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Visit list of one hospital (#1178), October 2026. Visits on 12 October:
 * - 10:07 linked automatically,
 * - 13:31 only a first assessment, self-referral, no candidate,
 * - 14:20 two open proposals.
 */
#[ResetDatabase]
final class ListVisitsControllerTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private const string PERIOD = '?from=2026-10-01&to=2026-10-31';

    private KernelBrowser $client;

    private Hospital $hospital;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->hospital = $this->hospital($this->user());

        $visits = $this->encounterImport($this->hospital, 'List visits');
        $allocations = $this->encounterImport($this->hospital, 'List allocations', ImportType::ALLOCATION);

        $linked = $this->visitAt($this->hospital, $visits, '10:07');
        $this->visitAt($this->hospital, $visits, null, '13:31', Sex::Male, 47, ArrivalMode::Self);
        $pending = $this->visitAt($this->hospital, $visits, '14:20');

        $this->pair($this->allocationAt($this->hospital, $allocations, '10:00'), $linked, MatchStatus::AutoAccepted);
        $this->pair($this->allocationAt($this->hospital, $allocations, '14:00'), $pending, MatchStatus::Proposed);
        $this->pair($this->allocationAt($this->hospital, $allocations, '14:05'), $pending, MatchStatus::Proposed);
    }

    public function testCaseViewerSeesEveryVisitOnceWithoutProposals(): void
    {
        $this->client->loginUser($this->caseViewer());
        $crawler = $this->list();

        self::assertSame(
            ['12.10.2026, 14:20' => 'unlinked', '12.10.2026, 13:31' => 'unlinked', '12.10.2026, 10:07' => 'linked'],
            $this->states($crawler),
            'Newest first; without REVIEW the visit with proposals is not linked.',
        );
        self::assertStringStartsWith('/explore/ed-visits/visit/', (string) $crawler->filter('[data-testid="encounter-visit-list-link"]')->first()->attr('href'));
        self::assertCount(0, $crawler->filter('#visit-link option[value="pending"]'));
        self::assertSelectorNotExists('[data-testid="encounter-visit-list-hospital-switch"]', 'Only one hospital.');
    }

    public function testReviewerSeesPendingVisitsAndCanFilterThem(): void
    {
        $this->client->loginUser($this->grantee([EncounterGrantAction::Review]));

        self::assertSame(['12.10.2026, 14:20' => 'pending', '12.10.2026, 13:31' => 'unlinked', '12.10.2026, 10:07' => 'linked'], $this->states($this->list()));
        self::assertSame(['12.10.2026, 14:20' => 'pending'], $this->states($this->list('&link=pending')));
        self::assertSelectorExists('[data-testid="encounter-visit-list-active-filters"]');
    }

    public function testFiltersByLinkStateAndArrivalRoute(): void
    {
        $this->client->loginUser($this->caseViewer());

        self::assertSame(['12.10.2026, 10:07' => 'linked'], $this->states($this->list('&link=linked')));
        self::assertSame(['12.10.2026, 13:31' => 'unlinked'], $this->states($this->list('&route[]=self')));
        self::assertSame(
            ['12.10.2026, 14:20' => 'unlinked', '12.10.2026, 13:31' => 'unlinked', '12.10.2026, 10:07' => 'linked'],
            $this->states($this->list('&link=pending')),
            'The pending filter is ignored without REVIEW.',
        );
    }

    public function testSortsByAgeAndPaginates(): void
    {
        $this->client->loginUser($this->caseViewer());

        self::assertSame(['12.10.2026, 13:31', '12.10.2026, 10:07', '12.10.2026, 14:20'], array_keys($this->states($this->list('&sortBy=age&orderBy=asc'))), 'Age 47 first; the two visits aged 70 keep the order of their ids.');
        self::assertCount(2, $this->states($this->list('&limit=2')));
        self::assertCount(1, $this->states($this->list('&limit=2&page=2')));
    }

    public function testAccessNeedsViewCasesOnTheHospital(): void
    {
        $this->client->loginUser($this->grantee([EncounterGrantAction::Upload]));
        $this->request();
        self::assertResponseStatusCodeSame(403, 'UPLOAD alone does not open cases.');

        $otherHospitalViewer = $this->user();
        $this->encounterGrant($this->hospital($this->user()), $otherHospitalViewer, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($otherHospitalViewer);
        $this->request();
        self::assertResponseStatusCodeSame(403, 'VIEW_CASES on another hospital.');

        $withoutBeta = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $this->encounterGrant($this->hospital, $withoutBeta, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($withoutBeta);
        $this->request();
        self::assertResponseStatusCodeSame(403, 'The grant needs the beta role.');

        $this->client->loginUser($this->admin());
        self::assertCount(3, $this->states($this->list()), 'Administrators without a grant.');
    }

    public function testHospitalChoiceRedirectsOrLists(): void
    {
        $this->client->loginUser($this->caseViewer());
        $this->client->request(Request::METHOD_GET, '/explore/ed-visits');
        self::assertResponseRedirects('/explore/ed-visits/'.$this->hospital->getPublicIdString());

        $this->client->loginUser($this->user());
        $this->client->request(Request::METHOD_GET, '/explore/ed-visits', server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="encounter-visit-list-no-hospitals"]');

        $other = $this->hospital($this->user());
        $this->visitAt($other, $this->encounterImport($other, 'Other visits'), '09:00');
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/ed-visits', server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-testid="encounter-visit-list-hospitals"] a'), 'Every hospital with visits.');
    }

    public function testNavigationEntryAndExploreTileNeedTheBetaRole(): void
    {
        $this->client->loginUser($this->caseViewer());
        $crawler = $this->client->request(Request::METHOD_GET, '/explore', server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="explore-tile-ed-visits"] a[href="/explore/ed-visits"]'), 'Tile on the Explore start page.');
        self::assertCount(2, $crawler->filter('a[href="/explore/ed-visits"]'), 'Tile and sidebar entry.');

        $this->client->loginUser($this->user(['ROLE_USER', 'ROLE_PARTICIPANT']));
        $crawler = $this->client->request(Request::METHOD_GET, '/explore', server: ['HTTP_Accept-Language' => 'en']);
        self::assertSelectorNotExists('[data-testid="explore-tile-ed-visits"]');
        self::assertCount(0, $crawler->filter('a[href="/explore/ed-visits"]'));
    }

    public function testMyHospitalsAndDetailPageLinkToTheList(): void
    {
        $owner = $this->hospital->getOwner();
        self::assertInstanceOf(User::class, $owner);
        $this->encounterGrant($this->hospital, $owner, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($owner);

        $crawler = $this->client->request(Request::METHOD_GET, '/hospitals', server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();
        $action = $crawler->filter(\sprintf('[data-testid="my-hospitals-card"][data-hospital-id="%d"] [data-action-key="encounter_visits"]', $this->hospital->getId()));
        self::assertCount(1, $action);
        self::assertSame('/explore/ed-visits/'.$this->hospital->getPublicIdString(), $action->attr('href'));

        $detail = (string) $this->list()->filter('[data-testid="encounter-visit-list-link"]')->first()->attr('href');
        $crawler = $this->client->request(Request::METHOD_GET, $detail, server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertSame('/explore/ed-visits/'.$this->hospital->getPublicIdString().'?from=2026-10-01&to=2026-10-31', $crawler->filter('[data-testid="encounter-visit-back"]')->attr('href'));
    }

    public function testGermanListHasNoMissingTranslations(): void
    {
        $this->client->loginUser($this->grantee([EncounterGrantAction::Review]));
        $this->client->enableProfiler();
        $this->client->request(Request::METHOD_GET, $this->url().'&link=pending', server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Notaufnahme-Besuche');
        $this->assertNoMissingTranslations($this->client->getProfile());
    }

    /**
     * @return array<string, string> visit time => link state, in list order
     */
    private function states(Crawler $crawler): array
    {
        $states = [];
        $crawler->filter('[data-testid="encounter-visit-list-row"]')->each(static function (Crawler $row) use (&$states): void {
            $states[trim($row->filter('[data-testid="encounter-visit-list-link"]')->text())] = (string) $row->filter('[data-testid="encounter-visit-list-state"]')->attr('data-state');
        });

        return $states;
    }

    private function url(): string
    {
        return '/explore/ed-visits/'.$this->hospital->getPublicIdString().self::PERIOD;
    }

    private function request(string $query = ''): Crawler
    {
        return $this->client->request(Request::METHOD_GET, $this->url().$query, server: ['HTTP_Accept-Language' => 'en']);
    }

    private function list(string $query = ''): Crawler
    {
        $crawler = $this->request($query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function caseViewer(): User
    {
        return $this->grantee([EncounterGrantAction::ViewCases]);
    }

    /**
     * @param list<EncounterGrantAction> $actions
     */
    private function grantee(array $actions): User
    {
        $user = $this->user();
        $this->encounterGrant($this->hospital, $user, $actions);

        return $user;
    }

    private function pair(Allocation $allocation, int $encounterId, MatchStatus $status): void
    {
        $em = $this->encounterEntityManager();
        $encounter = $em->find(Encounter::class, $encounterId);
        $managed = $em->find(Allocation::class, $allocation->getId());
        self::assertInstanceOf(Encounter::class, $encounter);
        self::assertInstanceOf(Allocation::class, $managed);

        $em->persist(new EncounterMatch($managed, $encounter, $status, AnchorSource::Admission, 7, new \DateTimeImmutable('2026-10-12 15:00'), 0.8, 8, [], 1, '1.2.0'));
        $em->flush();
    }
}
