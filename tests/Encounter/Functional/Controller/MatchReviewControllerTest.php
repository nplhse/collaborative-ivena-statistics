<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Infrastructure\Matching\MatchDecider;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Match review (ADR 029, #1054): access per hospital, each decision with its audit entry, stickiness
 * after a rerun, conflict resolution and swaps.
 *
 * All data on 12 October 2026 (MatchingDataFixtures): allocations female, 70 years, without physician
 * or work accident; visits by ambulance unless stated. A visit 0–15 minutes after the arrival scores
 * time +4, sex +2, age +2, route +1 = 9 points; alone 512 / (16 + 512) = 0.9697, an automatic link.
 */
#[ResetDatabase]
final class MatchReviewControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private KernelBrowser $client;

    private Hospital $hospital;

    private User $reviewer;

    private Import $ivena;

    private Import $ed;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::mockTime('2026-11-12 09:00:00');
        $this->hospital = $this->hospital($this->user());
        $this->reviewer = $this->user();
        $this->hospitalGrant($this->hospital, $this->reviewer);
        $this->encounterGrant($this->hospital, $this->reviewer, [EncounterGrantAction::ViewCases, EncounterGrantAction::Review]);
        $this->ivena = $this->encounterImport($this->hospital, 'IVENA', ImportType::ALLOCATION);
        $this->ed = $this->encounterImport($this->hospital, 'ED');
    }

    public function testReviewerSeesTheCountsWithDenominatorsAndTheLists(): void
    {
        // 10:00 → visit 10:05: automatic link. 12:00 → visit 12:20 at age 71: 2 + 2 + 1 + 1 = 6 points,
        // 64 / 80 = 0.80, a proposal. 14:00: no visit. One self-presenter at 18:00 stays unlinked.
        $linked = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $proposed = $this->allocationAt($this->hospital, $this->ivena, '12:00');
        $this->allocationAt($this->hospital, $this->ivena, '14:00');
        $this->visitAt($this->hospital, $this->ed, '10:05');
        $this->visitAt($this->hospital, $this->ed, '12:20', age: 71);
        $this->visitAt($this->hospital, $this->ed, '18:00', mode: ArrivalMode::Self);
        $this->matchDay($this->hospital);

        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/encounter/review');
        self::assertResponseRedirects($this->hospitalUrl());

        $crawler = $this->client->request(Request::METHOD_GET, $this->hospitalUrl().'?from=2026-10-01&to=2026-10-31');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-review-summary-allocations"] .card-title', '3');
        self::assertSame(['1', '0', '1', '1', '0'], array_map(
            static fn (string $key): string => trim($crawler->filter('[data-test="summary-'.$key.'"] td')->eq(1)->text()),
            ['automatic', 'confirmed', 'proposed', 'no_proposal', 'conflicts'],
        ));
        self::assertSame('33%', trim($crawler->filter('[data-test="summary-automatic"] td')->eq(2)->text()));
        self::assertSelectorTextContains('[data-test="encounter-review-summary-visits"] .card-title', '3');
        self::assertSame(['2', '1', '1'], $this->rowCells($crawler, 'summary-visits-ems'));
        self::assertSame(['1', '0', '1'], $this->rowCells($crawler, 'summary-visits-self'));
        self::assertSelectorTextContains('[data-test="encounter-review-agreement-note"]', 'not a probability');

        // Default list: proposals.
        self::assertCount(1, $crawler->filter('[data-test="encounter-review-list"] tbody [data-test="encounter-review-item"]'));
        self::assertStringContainsString('/allocations/'.$proposed->getId(), (string) $crawler->filter('[data-test="encounter-review-list"] tbody [data-test="encounter-review-item"]')->attr('href'));
        self::assertSelectorTextContains('[data-test="encounter-review-list"] tbody', '0.8');

        $crawler = $this->client->request(Request::METHOD_GET, $this->hospitalUrl().'?from=2026-10-01&to=2026-10-31&filter=automatic');
        self::assertStringContainsString('/allocations/'.$linked->getId(), (string) $crawler->filter('[data-test="encounter-review-list"] tbody [data-test="encounter-review-item"]')->attr('href'));
        self::assertSelectorTextContains('[data-test="encounter-review-list"] tbody', 'Automatic link');

        $crawler = $this->client->request(Request::METHOD_GET, $this->hospitalUrl().'?from=2026-10-01&to=2026-10-31&filter=no_proposal');
        self::assertSelectorTextContains('[data-test="encounter-review-list"] tbody', '12.10.2026 14:00');

        $crawler = $this->client->request(Request::METHOD_GET, $this->hospitalUrl().'?from=2026-10-01&to=2026-10-31&filter=unlinked_visits&route=self');
        self::assertCount(1, $crawler->filter('[data-test="encounter-review-list"] tbody [data-test="encounter-review-item"]'));
        self::assertSelectorTextContains('[data-test="encounter-review-list"] tbody', 'Self-presenter');
    }

    public function testAllocationPageExplainsEveryCandidate(): void
    {
        // Visit A at 10:05 (9 points) and visit B with only a first assessment contact at 10:30, age 72
        // (time +2, sex +2, age 0, route +1 = 5): 512 / (16 + 512 + 32) = 0.9143 and 32 / 560 = 0.0571.
        $allocation = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $best = $this->visitAt($this->hospital, $this->ed, '10:05');
        $this->visitAt($this->hospital, $this->ed, null, '10:30', age: 72);
        $this->matchDay($this->hospital);

        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, $this->allocationUrl($allocation));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-review-allocation"]', '12.10.2026 10:00');
        self::assertSelectorTextContains('[data-test="encounter-review-current"]', 'Linked to visit #'.$best);
        self::assertSelectorExists('[data-test="encounter-review-automatic-origin"]');
        $candidates = $crawler->filter('[data-test="encounter-review-candidate"]');
        self::assertCount(2, $candidates);
        self::assertSame((string) $best, $candidates->eq(0)->attr('data-encounter'));
        self::assertStringContainsString('0.91', $candidates->eq(0)->filter('[data-test="encounter-review-agreement"]')->text());
        // Both visits are in pass 1 (the second one at +30 minutes, age ±2), so the stored score is the same.
        self::assertStringContainsString('stored: 0.91 (pass 1)', $candidates->eq(0)->filter('[data-test="encounter-review-agreement"]')->text());
        self::assertSame(['for', 'for', 'for', 'for', 'neutral', 'neutral'], $candidates->eq(0)->filter('[data-test="encounter-review-features"] tbody tr')->each(static fn (Crawler $row): string => (string) $row->attr('data-direction')));
        self::assertStringContainsString('0.06', $candidates->eq(1)->filter('[data-test="encounter-review-agreement"]')->text());
        self::assertSelectorExists('[data-test="encounter-review-substitute"]');
        self::assertSame('neutral', $candidates->eq(1)->filter('[data-test="feature-age"]')->attr('data-direction'));
        self::assertSelectorTextContains('[data-test="encounter-review-leave-open"]', 'Leave open');
    }

    public function testConfirmingAProposalIsAuditedAndStaysAfterARerun(): void
    {
        $allocation = $this->allocationAt($this->hospital, $this->ivena, '12:00');
        $visit = $this->visitAt($this->hospital, $this->ed, '12:20', age: 71);
        $this->matchDay($this->hospital);
        self::assertSame([sprintf('%d-%d proposed 0.8000', $allocation->getId(), $visit)], $this->matchRows($this->hospital));

        $this->client->loginUser($this->reviewer);
        $this->decide($allocation, $visit, 'confirm');

        self::assertResponseRedirects($this->allocationUrl($allocation));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The link has been confirmed.');
        self::assertSame([sprintf('%d-%d confirmed 0.8000', $allocation->getId(), $visit)], $this->matchRows($this->hospital));
        $match = $this->match($allocation, $visit);
        self::assertSame($this->reviewer->getId(), $match->getDecidedBy()?->getId());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 09:00:00'), $match->getDecidedAt());
        self::assertSame(['decision' => 'confirm', 'previous_status' => 'proposed'], array_intersect_key($this->auditMetadata(MatchDecider::INTENT_DECIDED), ['decision' => 1, 'previous_status' => 1]));

        // A better visit later on changes nothing: the decision is sticky.
        $this->visitAt($this->hospital, $this->ed, '12:02', '12:03');
        $this->matchDay($this->hospital);
        self::assertSame([sprintf('%d-%d confirmed 0.8000', $allocation->getId(), $visit)], $this->matchRows($this->hospital));
    }

    public function testRejectingAnAutomaticLinkIsStickyAfterARerun(): void
    {
        $allocation = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $visit = $this->visitAt($this->hospital, $this->ed, '10:05');
        $this->matchDay($this->hospital);

        $this->client->loginUser($this->reviewer);
        $this->decide($allocation, $visit, 'reject');

        self::assertResponseRedirects();
        self::assertSame([sprintf('%d-%d rejected 0.9697', $allocation->getId(), $visit)], $this->matchRows($this->hospital));
        self::assertSame(['decision' => 'reject', 'previous_status' => 'auto_accepted'], array_intersect_key($this->auditMetadata(MatchDecider::INTENT_DECIDED), ['decision' => 1, 'previous_status' => 1]));

        $this->matchDay($this->hospital);
        self::assertSame([sprintf('%d-%d rejected 0.9697', $allocation->getId(), $visit)], $this->matchRows($this->hospital));
    }

    public function testChangingTheLinkRejectsThePreviousPair(): void
    {
        // Linked visit at 10:05 (9 points; with the visit at 10:30 at age 72 as second candidate in pass 1:
        // 512 / 560 = 0.9143). The person picks the 10:30 visit instead (5 points, not stored by the
        // matcher); the new pair stores its review-window scoring: 32 / 560 = 0.0571.
        $allocation = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $linked = $this->visitAt($this->hospital, $this->ed, '10:05');
        $other = $this->visitAt($this->hospital, $this->ed, '10:30', age: 72);
        $this->matchDay($this->hospital);

        $this->client->loginUser($this->reviewer);
        $this->decide($allocation, $other, 'confirm');
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'The link has been changed');
        self::assertSame([
            sprintf('%d-%d rejected 0.9143', $allocation->getId(), $linked),
            sprintf('%d-%d confirmed 0.0571', $allocation->getId(), $other),
        ], $this->matchRows($this->hospital));
        self::assertSame(['time' => 2, 'sex' => 2, 'age' => 0, 'arrival_route' => 1, 'physician' => 0, 'work_accident' => 0], $this->match($allocation, $other)->getContributions());
        self::assertSame(['decision' => 'change', 'replaced_encounter_id' => $linked], array_intersect_key($this->auditMetadata(MatchDecider::INTENT_DECIDED), ['decision' => 1, 'replaced_encounter_id' => 1]));

        $this->matchDay($this->hospital);
        self::assertCount(2, $this->matchRows($this->hospital));
        self::assertStringContainsString('confirmed', $this->matchRows($this->hospital)[1]);
    }

    public function testConfirmingOneSideOfAConflictResolvesIt(): void
    {
        // Two allocations reach 0.9697 for the visit at 10:06: no link, two conflict proposals.
        $first = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $second = $this->allocationAt($this->hospital, $this->ivena, '10:04');
        $visit = $this->visitAt($this->hospital, $this->ed, '10:06');
        $this->matchDay($this->hospital);

        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, $this->hospitalUrl().'?from=2026-10-01&to=2026-10-31&filter=conflicts');
        self::assertCount(2, $crawler->filter('[data-test="encounter-review-list"] tbody [data-test="encounter-review-conflict"]'));
        self::assertSame('2', trim($crawler->filter('[data-test="summary-conflicts"] td')->eq(1)->text()));
        $crawler = $this->client->request(Request::METHOD_GET, $this->allocationUrl($first));
        self::assertSelectorTextContains('[data-test="encounter-review-rivals"]', 'Allocation #'.$second->getId());

        $this->decide($first, $visit, 'confirm');

        self::assertSame([sprintf('%d-%d confirmed 0.9697 conflict', $first->getId(), $visit)], $this->matchRows($this->hospital));
        $crawler = $this->client->request(Request::METHOD_GET, $this->hospitalUrl().'?from=2026-10-01&to=2026-10-31&filter=conflicts');
        self::assertSame('0', trim($crawler->filter('[data-test="summary-conflicts"] td')->eq(1)->text()));
        self::assertSame(['1', '1'], [
            trim($crawler->filter('[data-test="summary-confirmed"] td')->eq(1)->text()),
            trim($crawler->filter('[data-test="summary-no_proposal"] td')->eq(1)->text()),
        ]);
    }

    public function testAVisitLinkedElsewhereCannotBeTakenWithoutASwap(): void
    {
        $first = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $second = $this->allocationAt($this->hospital, $this->ivena, '14:00');
        $visit = $this->visitAt($this->hospital, $this->ed, '10:05');
        $this->visitAt($this->hospital, $this->ed, '14:05');
        $this->matchDay($this->hospital);
        $before = $this->matchRows($this->hospital);

        $this->client->loginUser($this->reviewer);
        $this->decide($second, $visit, 'confirm');
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'This visit is linked to another allocation.');
        self::assertSame($before, $this->matchRows($this->hospital));
        self::assertNotNull($first->getId());
    }

    public function testApplyingAChainSwapLinksBothAllocations(): void
    {
        // Allocation A at 10:00 takes visit W at 10:10 automatically in pass 1 (9 points). Allocation B at
        // 10:05, age 72, would score 4 + 2 + 0 + 1 = 7 points with W; the self-presenter V at 10:05 scores
        // 4 + 2 + 2 − 2 = 6 with A. Swap: A–V and B–W, one link more, 6 + 7 − 9 = 4 points more.
        $a = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $b = $this->allocationAt($this->hospital, $this->ivena, '10:05', age: 72);
        $w = $this->visitAt($this->hospital, $this->ed, '10:10');
        $v = $this->visitAt($this->hospital, $this->ed, '10:05', mode: ArrivalMode::Self);
        $this->matchDay($this->hospital);
        self::assertStringContainsString(sprintf('%d-%d auto_accepted', $a->getId(), $w), implode('|', $this->matchRows($this->hospital)));

        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, $this->hospitalUrl().'?from=2026-10-01&to=2026-10-31&filter=swaps');
        self::assertSame('1', trim($crawler->filter('[data-test="summary-swaps"] td')->eq(1)->text()));
        self::assertSelectorTextContains('[data-test="encounter-review-list"]', 'One more link');

        $crawler = $this->client->request(Request::METHOD_GET, $this->allocationUrl($a));
        $form = $crawler->filter('[data-test="encounter-review-apply-swap"]')->form();
        self::assertSame(sprintf('%d:%d:%d:%d', $a->getId(), $w, $v, $b->getId()), $form['swap']->getValue());
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'The swap has been applied.');
        $rows = $this->matchRows($this->hospital);
        self::assertContains(sprintf('%d-%d rejected 0.9697', $a->getId(), $w), $rows);
        self::assertSame(['confirmed', 'confirmed'], [$this->match($a, $v)->getStatus()->value, $this->match($b, $w)->getStatus()->value]);
        self::assertCount(3, $rows);
        self::assertSame(['kind' => 'chain', 'other_allocation_id' => $b->getId()], array_intersect_key($this->auditMetadata(MatchDecider::INTENT_SWAPPED), ['kind' => 1, 'other_allocation_id' => 1]));

        $this->matchDay($this->hospital);
        self::assertSame($rows, $this->matchRows($this->hospital));
    }

    public function testAnOutdatedSwapChangesNothing(): void
    {
        $a = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $this->visitAt($this->hospital, $this->ed, '10:10');
        $this->matchDay($this->hospital);
        $before = $this->matchRows($this->hospital);

        $this->client->loginUser($this->reviewer);
        $this->client->request(Request::METHOD_POST, $this->allocationUrl($a).'/swap', [
            '_token' => $this->csrfToken($a),
            'swap' => $a->getId().':1:2:0',
        ]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'The swap is no longer proposed');
        self::assertSame($before, $this->matchRows($this->hospital));
    }

    public function testAccessNeedsReviewOnTheHospital(): void
    {
        $allocation = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $visit = $this->visitAt($this->hospital, $this->ed, '10:05');
        $this->matchDay($this->hospital);
        $other = $this->hospital($this->user());
        $otherAllocation = $this->allocationAt($other, $this->encounterImport($other, 'IVENA other', ImportType::ALLOCATION), '10:00');

        $viewer = $this->user();
        $this->hospitalGrant($this->hospital, $viewer);
        $this->encounterGrant($this->hospital, $viewer, [EncounterGrantAction::ViewCases]);
        $noBeta = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $this->hospitalGrant($this->hospital, $noBeta);
        $this->encounterGrant($this->hospital, $noBeta, [EncounterGrantAction::ViewCases, EncounterGrantAction::Review]);
        $admin = $this->admin();

        $this->client->loginUser($viewer);
        $this->client->request(Request::METHOD_GET, $this->hospitalUrl());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request(Request::METHOD_GET, $this->allocationUrl($allocation));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request(Request::METHOD_POST, $this->allocationUrl($allocation).'/decide', ['decision' => 'reject', 'encounter' => $visit]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request(Request::METHOD_GET, '/encounter/review');
        self::assertSelectorExists('[data-test="encounter-review-no-hospitals"]');

        // A reviewer of another hospital: 403 there; this hospital's allocation under their own hospital: 404.
        $this->client->loginUser($this->reviewer);
        $this->client->request(Request::METHOD_GET, '/encounter/review/'.$other->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request(Request::METHOD_GET, '/encounter/review/'.$this->hospital->getId().'/allocations/'.$otherAllocation->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request(Request::METHOD_POST, '/encounter/review/'.$this->hospital->getId().'/allocations/'.$otherAllocation->getId().'/decide', ['_token' => $this->csrfToken($allocation), 'decision' => 'confirm', 'encounter' => $visit]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Without the beta role a grant does not help.
        $this->client->loginUser($noBeta);
        $this->client->request(Request::METHOD_GET, $this->hospitalUrl());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Administrators hold REVIEW on every hospital.
        $this->client->loginUser($admin);
        $this->client->request(Request::METHOD_GET, $this->allocationUrl($allocation));
        self::assertResponseIsSuccessful();
        $this->decide($allocation, $visit, 'confirm');
        self::assertSame([sprintf('%d-%d confirmed 0.9697', $allocation->getId(), $visit)], $this->matchRows($this->hospital));

        self::assertSame(1, $this->auditCount());
    }

    public function testDecisionsNeedAValidToken(): void
    {
        $allocation = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $visit = $this->visitAt($this->hospital, $this->ed, '10:05');
        $this->matchDay($this->hospital);

        $this->client->loginUser($this->reviewer);
        $this->client->request(Request::METHOD_POST, $this->allocationUrl($allocation).'/decide', ['_token' => 'wrong', 'decision' => 'reject', 'encounter' => $visit]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame([sprintf('%d-%d auto_accepted 0.9697', $allocation->getId(), $visit)], $this->matchRows($this->hospital));
    }

    private function decide(Allocation $allocation, int $visit, string $decision): void
    {
        $this->client->request(Request::METHOD_POST, $this->allocationUrl($allocation).'/decide', [
            '_token' => $this->csrfToken($allocation),
            'decision' => $decision,
            'encounter' => $visit,
        ]);
    }

    /**
     * The token as the allocation page renders it (CSRF tokens live in the session).
     */
    private function csrfToken(Allocation $allocation): string
    {
        $crawler = $this->client->request(Request::METHOD_GET, $this->allocationUrl($allocation));
        $token = $crawler->filter('form input[name="_token"]');

        return $token->count() > 0 ? (string) $token->first()->attr('value') : 'missing';
    }

    private function hospitalUrl(): string
    {
        return '/encounter/review/'.$this->hospital->getId();
    }

    private function allocationUrl(Allocation $allocation): string
    {
        return sprintf('/encounter/review/%d/allocations/%d', (int) $allocation->getHospital()?->getId(), (int) $allocation->getId());
    }

    private function match(Allocation $allocation, int $visit): EncounterMatch
    {
        $this->encounterEntityManager()->clear();
        $match = $this->encounterEntityManager()->getRepository(EncounterMatch::class)->findOneBy(['allocation' => $allocation->getId(), 'encounter' => $visit]);
        self::assertInstanceOf(EncounterMatch::class, $match);

        return $match;
    }

    /**
     * Metadata of the latest audit entry of the intent.
     *
     * @return array<string, mixed>
     */
    private function auditMetadata(string $intent): array
    {
        $entries = array_values(array_filter(
            $this->encounterEntityManager()->getRepository(AuditEntry::class)->findBy(['entityClass' => EncounterMatch::class], ['id' => 'DESC']),
            static fn (AuditEntry $entry): bool => ($entry->getMetadata()['intent'] ?? null) === $intent,
        ));
        self::assertNotEmpty($entries);

        /** @var array<string, mixed> $metadata */
        $metadata = $entries[0]->getMetadata()['intent_metadata'] ?? $entries[0]->getMetadata();

        return $metadata;
    }

    private function auditCount(): int
    {
        return \count($this->encounterEntityManager()->getRepository(AuditEntry::class)->findBy(['entityClass' => EncounterMatch::class]));
    }

    /**
     * @return list<string>
     */
    private function rowCells(Crawler $crawler, string $testId): array
    {
        $cells = $crawler->filter('[data-test="'.$testId.'"] td');

        return [trim($cells->eq(1)->text()), explode(' ', trim($cells->eq(2)->text()))[0], trim($cells->eq(3)->text())];
    }
}
