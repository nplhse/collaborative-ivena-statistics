<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Entity\MatchValidationSample;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;
use App\Encounter\Infrastructure\Matching\MatchDecider;
use App\Encounter\Infrastructure\Validation\MatchValidator;
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
 * Match validation (#1057): drawing a stratified sample, verdicts with their links ("correct" confirms,
 * "wrong" rejects, another visit or a missed partner is linked, a link elsewhere only through the review's
 * swap), the report per score band and access.
 *
 * Data on 12 October 2026 (MatchingDataFixtures: allocations female, 70 years; visits by ambulance):
 * - 10:00 → visit 10:05: 9 points, 512 / 528 = 0.9697, automatic link, band 0.90–0.99
 * - 12:00 → visit 12:20 at age 71: 3 + 2 + 1 + 1 = 7 points, 128 / 144 = 0.89, proposal, band 0.70–0.90
 * - 14:00, 15:00: no visit, allocations without link
 * - visit 18:00 by ambulance: EMS visit without link; visit 19:00 self-presenter: not sampled
 */
#[ResetDatabase]
final class MatchValidationControllerTest extends WebTestCase
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

    public function testReviewerDrawsASampleJudgesEveryCaseAndReadsTheReport(): void
    {
        $linked = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $proposed = $this->allocationAt($this->hospital, $this->ivena, '12:00');
        $this->allocationAt($this->hospital, $this->ivena, '14:00');
        $this->allocationAt($this->hospital, $this->ivena, '15:00');
        $linkedVisit = $this->visitAt($this->hospital, $this->ed, '10:05');
        $proposedVisit = $this->visitAt($this->hospital, $this->ed, '12:20', age: 71);
        $emsVisit = $this->visitAt($this->hospital, $this->ed, '18:00');
        $this->visitAt($this->hospital, $this->ed, '19:00', mode: ArrivalMode::Self);
        $this->matchDay($this->hospital);
        $before = $this->matchRows($this->hospital);

        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, $this->validationUrl().'?from=2026-10-01&to=2026-10-31');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-validation-samples"]', 'No sample drawn yet');

        // One case per stratum and band: the second allocation without link is not drawn.
        $form = $crawler->selectButton('Draw sample')->form(['validation_sample[sample_size]' => '1']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="encounter-validation-sample-meta"]', 'Matcher '.MatchPlanner::VERSION);

        // Judged of sampled and the population when drawn, in the summary column.
        self::assertSame(['0 of 1 judged', '1 in period'], $this->reportCells($crawler, 'report-automatic-0.90–0.99', ['progress', 'population']));
        self::assertSame(['0 of 1 judged', '1 in period'], $this->reportCells($crawler, 'report-proposal-0.70–0.90', ['progress', 'population']));
        self::assertSame(['0 of 1 judged', '2 in period'], $this->reportCells($crawler, 'report-unlinked_allocation', ['progress', 'population']));
        self::assertSame(['0 of 1 judged', '1 in period'], $this->reportCells($crawler, 'report-unlinked_visit', ['progress', 'population']));
        self::assertCount(4, $crawler->filter('[data-test="encounter-validation-item"]'));
        // Drawing changes no link.
        self::assertSame($before, $this->matchRows($this->hospital));

        // Case 1, the automatic link: one question, one comparison with the sampled visit; the only other
        // visits (12:20, 18:00) lie outside the review window, so there is no alternative.
        $crawler = $this->client->click($crawler->filter('[data-test="encounter-validation-continue"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-validation-origin"]', 'Sampled as Automatic link, agreement 0.97 (band 0.90–0.99), pass 1, matcher '.MatchPlanner::VERSION);
        self::assertSelectorTextContains('[data-test="encounter-validation-question"] .card-title', 'Do allocation and visit belong together?');
        self::assertCount(1, $crawler->filter('[data-test="encounter-validation-candidate"]'));
        self::assertSame((string) $linkedVisit, $crawler->filter('[data-test="encounter-validation-candidate"]')->attr('data-encounter'));
        self::assertSelectorTextContains('[data-test="encounter-validation-alternatives"]', 'Alternatives (0)');
        self::assertSelectorTextContains('[data-test="encounter-validation-alternatives"]', 'No other candidates in the time window');
        self::assertSelectorTextContains('[data-test="encounter-validation-decision-note"]', 'Changing the verdict later undoes no link');
        // The decisions sit in the narrow right column, from "correct" down to "wrong".
        self::assertSame(
            ['encounter-validation-verdict-correct', 'encounter-validation-verdict-unclear', 'encounter-validation-wrong-other', 'encounter-validation-verdict-wrong', 'encounter-validation-skip'],
            $crawler->filter('.col-lg-4 [data-test="encounter-validation-decision"] .btn')->each(static fn (Crawler $button): string => (string) $button->attr('data-test')),
        );
        // "Correct" confirms the automatic link.
        $crawler = $this->verdict($crawler, 'correct');
        self::assertSelectorTextContains('.alert-success', 'the link was confirmed');
        self::assertContains(sprintf('%d-%d confirmed 0.9697', $linked->getId(), $linkedVisit), $this->matchRows($this->hospital));
        self::assertTrue($this->validationAudit()['pair_confirmed']);

        // Case 2, the proposal: "wrong, no matching visit" rejects it.
        self::assertSelectorTextContains('[data-test="encounter-validation-origin"]', 'band 0.70–0.90');
        $crawler = $this->verdict($crawler, 'wrong');
        self::assertSelectorTextContains('.alert-success', 'the pair was rejected');
        self::assertContains(sprintf('%d-%d rejected 0.8889', $proposed->getId(), $proposedVisit), $this->matchRows($this->hospital));
        self::assertSame('wrong', $this->validationAudit()['verdict']);
        self::assertTrue($this->validationAudit()['pair_rejected']);

        // Case 3, an allocation without link: no candidate in the review window, only "no partner" and "unclear".
        self::assertSelectorTextContains('h2, .page-title', 'Allocation without link');
        self::assertSelectorTextContains('[data-test="encounter-validation-options"]', 'No visit in the review window');
        self::assertCount(0, $crawler->filter('[data-test="encounter-validation-verdict-correct"]'));
        $crawler = $this->verdict($crawler, 'no_partner');

        // Case 4, the EMS visit: no allocation in reach.
        self::assertSelectorTextContains('[data-test="encounter-validation-visit"]', 'Visit #'.$emsVisit);
        self::assertSelectorTextContains('[data-test="encounter-validation-options"]', 'No allocation in reach');
        $crawler = $this->verdict($crawler, 'no_partner');
        self::assertStringEndsWith($this->validationUrl().'/'.$this->sample()->getId(), $this->client->getRequest()->getUri());
        self::assertSelectorTextContains('.alert-info', 'Every case of the sample has a verdict');

        // Verdicts and share per entry; "wrong" is split into another visit and no partner.
        self::assertSame(['1 correct · 0 wrong, other visit · 0 wrong, no partner', '100%'], $this->reportCells($crawler, 'report-automatic-0.90–0.99', ['counts', 'share-value']));
        self::assertSame(['0 correct · 0 wrong, other visit · 1 wrong, no partner', '0%'], $this->reportCells($crawler, 'report-proposal-0.70–0.90', ['counts', 'share-value']));
        self::assertSame(['0 missed · 1 no partner', '0%'], $this->reportCells($crawler, 'report-unlinked_allocation', ['counts', 'share-value']));
        self::assertSame(['0 missed · 1 no partner', '0%'], $this->reportCells($crawler, 'report-unlinked_visit', ['counts', 'share-value']));
        // Cases left, summary right.
        self::assertCount(1, $crawler->filter('.col-lg-8 [data-test="encounter-validation-items"]'));
        self::assertCount(3, $crawler->filter('.col-lg-4 [data-test="encounter-validation-sample-report"] .datagrid'), 'automatic, proposals, without link');
        self::assertSelectorTextContains('[data-test="weighted-automatic"]', '100%');
        self::assertSelectorTextContains('[data-test="encounter-validation-report-note"]', 'not a hit probability');

        // The overview adds up every sample per matcher version.
        $this->client->request(Request::METHOD_GET, $this->validationUrl());
        self::assertSelectorExists('[data-test="encounter-validation-combined"][data-version="'.MatchPlanner::VERSION.'"]');
    }

    /**
     * Allocation A 10:00 (female, 70). Visit W 10:05: time core 4 + sex 2 + age 2 + EMS 1 = 9 points. Visit V
     * 11:00, 60 minutes late: time outside −2 + 2 + 2 + 1 = 3 points, outside pass 1 (up to 45 minutes late).
     * Pass 1 links A–W automatically with 512 / (16 + 512) = 0.9697 (band 0.90–0.99); V stays without link
     * and is A's only alternative in the review window.
     */
    public function testWrongWithAnotherVisitLinksItInsteadAndALaterVerdictUndoesNoLink(): void
    {
        $allocation = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $sampled = $this->visitAt($this->hospital, $this->ed, '10:05');
        $other = $this->visitAt($this->hospital, $this->ed, '11:00');
        $this->matchDay($this->hospital);
        self::assertSame([sprintf('%d-%d auto_accepted 0.9697', $allocation->getId(), $sampled)], $this->matchRows($this->hospital));

        $sample = $this->drawEmpty($this->hospital);
        $item = $this->itemOf($sample, ValidationStratum::Automatic);

        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, $this->itemUrl($sample, $item));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-validation-alternatives"] summary', 'Alternatives (1)');
        $option = $crawler->filter('[data-test="encounter-validation-alternatives"] [data-test="encounter-validation-option"]');
        self::assertCount(1, $option);
        self::assertSame((string) $other, $option->attr('data-partner'));
        self::assertSame('confirm', $option->attr('data-plan'));
        self::assertSame('+3', trim(explode(' ', trim($option->filter('[data-test="option-score"]')->text()))[0]));
        self::assertCount(0, $option->filter('[data-test="mark-time"]'), 'Marks: sex, age and route only; time is the difference.');
        self::assertSame(['for', 'for', 'for'], $option->filter('[data-test^="mark-"]')->each(static fn (Crawler $mark): string => (string) $mark->attr('data-direction')));
        // The expanded row holds the full comparison.
        self::assertCount(1, $option->filter('[data-test="encounter-review-features"]'));

        // "Link instead": verdict wrong, A–W rejected, A–V confirmed, in one step. A–V is stored with its
        // agreement among A's candidates: 8 / (16 + 512 + 8) = 0.0149.
        $this->client->submit($crawler->selectButton('Link instead')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'the chosen partner was linked');
        self::assertSame([
            sprintf('%d-%d rejected 0.9697', $allocation->getId(), $sampled),
            sprintf('%d-%d confirmed 0.0149', $allocation->getId(), $other),
        ], $this->matchRows($this->hospital));
        $item = $this->reload($item);
        self::assertSame(ValidationVerdict::Wrong, $item->getVerdict());
        self::assertSame($other, $item->getReplacementEncounter()?->getId());

        // One audit intent: the verdict and both decisions of the pairs.
        $audit = $this->validationAudit();
        self::assertSame(['action' => 'confirm', 'allocation_id' => $allocation->getId(), 'encounter_id' => $other, 'swap' => null], $audit['link']);
        self::assertSame($other, $audit['replacement_encounter_id']);
        $matchIntents = array_unique(array_map(
            static fn (AuditEntry $entry): ?string => $entry->getMetadata()['intent'] ?? null,
            $this->encounterEntityManager()->getRepository(AuditEntry::class)->findBy(['entityClass' => EncounterMatch::class]),
        ));
        self::assertSame([MatchValidator::INTENT_VERDICT], array_values($matchIntents));

        // The report counts it as wrong, another visit.
        $crawler = $this->client->request(Request::METHOD_GET, $this->validationUrl().'/'.$sample->getId());
        self::assertSame(['0 correct · 1 wrong, other visit · 0 wrong, no partner'], $this->reportCells($crawler, 'report-automatic-0.90–0.99', ['counts']));

        // Changing the verdict to "correct" keeps the links as they are (A–W stays rejected) and drops the replacement.
        $crawler = $this->client->request(Request::METHOD_GET, $this->itemUrl($sample, $item));
        self::assertSelectorTextContains('[data-test="encounter-validation-current-verdict"]', 'Wrong – visit #'.$other.' fits');
        self::assertSame('linked', $crawler->filter('[data-test="encounter-validation-option"]')->attr('data-plan'));
        $this->verdict($crawler, 'correct');
        self::assertSame([
            sprintf('%d-%d rejected 0.9697', $allocation->getId(), $sampled),
            sprintf('%d-%d confirmed 0.0149', $allocation->getId(), $other),
        ], $this->matchRows($this->hospital));
        self::assertNull($this->reload($item)->getReplacementEncounter());
    }

    /**
     * B 10:00 and visit V 10:05, both female 70: 9 points; pass 1 (age difference up to 2) links B–V
     * automatically with 512 / 528 = 0.9697. X 10:00, age 66, is only a review candidate of B: 4 + 2 − 2 (age
     * difference 4) + 1 = 5 points. A 10:05, age 72: V scores 4 + 2 + 0 (age difference 2) + 1 = 7 points with
     * A (128 / 144 = 0.89, a proposal the link of V removes); X is no candidate of A (age difference 6). The
     * review offers the chain for B (both new pairs reach the proposal threshold alone): B takes X, A takes V.
     */
    public function testLinkingAVisitOfAnotherAllocationAppliesTheReviewSwap(): void
    {
        $b = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $a = $this->allocationAt($this->hospital, $this->ivena, '10:05', age: 72);
        $v = $this->visitAt($this->hospital, $this->ed, '10:05');
        $x = $this->visitAt($this->hospital, $this->ed, '10:00', age: 66);
        $this->matchDay($this->hospital);
        self::assertSame([sprintf('%d-%d auto_accepted 0.9697', $b->getId(), $v)], $this->matchRows($this->hospital));

        $sample = $this->drawEmpty($this->hospital);
        $item = $this->itemOf($sample, ValidationStratum::UnlinkedAllocation);
        self::assertSame($a->getId(), $item->getAllocation()?->getId());

        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, $this->itemUrl($sample, $item));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-validation-question"] .card-title', 'Is one of the visits the patient of this allocation?');
        $option = $crawler->filter('[data-test="encounter-validation-option"]');
        self::assertCount(1, $option);
        self::assertSame(['swap', (string) $v], [$option->attr('data-plan'), $option->attr('data-partner')]);
        self::assertStringContainsString('Linked to allocation #'.$b->getId(), $option->filter('[data-test="option-linked"]')->text());
        self::assertCount(1, $option->filter('[data-test="encounter-validation-swap"]'));

        $this->client->submit($crawler->selectButton('Link with swap')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'linked with the review’s swap');
        $rows = $this->matchRows($this->hospital);
        self::assertContains(sprintf('%d-%d rejected 0.9697', $b->getId(), $v), $rows);
        self::assertContains(sprintf('%d-%d confirmed', $a->getId(), $v), array_map(static fn (string $row): string => substr($row, 0, strrpos($row, ' ') ?: null), $rows));
        self::assertContains(sprintf('%d-%d confirmed', $b->getId(), $x), array_map(static fn (string $row): string => substr($row, 0, strrpos($row, ' ') ?: null), $rows));
        $item = $this->reload($item);
        self::assertSame(ValidationVerdict::Missed, $item->getVerdict());
        self::assertNull($item->getReplacementEncounter(), 'Only pairs keep a replacement.');
        self::assertSame('swap', $this->validationAudit()['link']['action'] ?? null);
    }

    /**
     * As above without visit X: B–V scores 512 / 528 = 0.9697 and the review offers no swap, so V is not taken
     * from B; the item points to B's review and a forged request writes nothing.
     */
    public function testLinkingAVisitOfAnotherAllocationWithoutSwapIsRefused(): void
    {
        $b = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $this->allocationAt($this->hospital, $this->ivena, '10:05', age: 72);
        $v = $this->visitAt($this->hospital, $this->ed, '10:05');
        $this->matchDay($this->hospital);
        $before = $this->matchRows($this->hospital);
        self::assertSame([sprintf('%d-%d auto_accepted 0.9697', $b->getId(), $v)], $before);

        $sample = $this->drawEmpty($this->hospital);
        $item = $this->itemOf($sample, ValidationStratum::UnlinkedAllocation);

        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, $this->itemUrl($sample, $item));
        $option = $crawler->filter('[data-test="encounter-validation-option"]');
        self::assertSame('refused', $option->attr('data-plan'));
        self::assertCount(0, $option->filter('[data-test="encounter-validation-link"]'));
        self::assertStringEndsWith(sprintf('/allocations/%d?from=2026-10-01&to=2026-10-31', (int) $b->getId()), (string) $option->filter('[data-test="encounter-validation-refused"]')->attr('href'));

        $token = (string) $crawler->filter('#encounter-validation-form input[name="_token"]')->attr('value');
        $this->client->request(Request::METHOD_POST, $this->itemUrl($sample, $item), ['_token' => $token, 'partner' => (string) $v]);
        self::assertResponseRedirects($this->itemUrl($sample, $item));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'Nothing saved: allocation #'.$b->getId().' is linked otherwise');
        self::assertSame($before, $this->matchRows($this->hospital));
        self::assertNull($this->reload($item)->getVerdict());

        // Only options of the item can be linked; "missed" needs a partner.
        $this->client->request(Request::METHOD_POST, $this->itemUrl($sample, $item), ['_token' => $token, 'partner' => '999999']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->client->request(Request::METHOD_POST, $this->itemUrl($sample, $item), ['_token' => $token, 'verdict' => 'missed']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    /**
     * Visit V 10:05 (female 70) without link: allocation A 10:00 (female, 70) would score 9 points, but A is
     * confirmed with visit W 10:10 by a person (the review's sticky decision), so the matcher leaves V alone.
     * Allocation C 10:20 (female, 70) has no link: V, 15 minutes early, scores time near 2 + 2 + 2 + 1 = 7
     * points with C.
     */
    public function testUnlinkedVisitLinksAnAllocationWithoutLinkAndRefusesAConfirmedOne(): void
    {
        $a = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $w = $this->visitAt($this->hospital, $this->ed, '10:10', age: 71);
        $this->matchDay($this->hospital);
        $decider = self::getContainer()->get(MatchDecider::class);
        self::assertInstanceOf(MatchDecider::class, $decider);
        $decider->confirm((int) $this->hospital->getId(), (int) $a->getId(), $w, $this->reviewer);
        $c = $this->allocationAt($this->hospital, $this->ivena, '10:20');
        $v = $this->visitAt($this->hospital, $this->ed, '10:05');

        $sample = $this->drawEmpty($this->hospital);
        $item = $this->itemOf($sample, ValidationStratum::UnlinkedVisit);
        self::assertSame($v, $item->getEncounter()?->getId());

        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, $this->itemUrl($sample, $item));
        self::assertResponseIsSuccessful();
        $plans = [];
        $crawler->filter('[data-test="encounter-validation-option"]')->each(static function (Crawler $option) use (&$plans): void {
            $plans[(int) $option->attr('data-partner')] = $option->attr('data-plan');
        });
        self::assertSame([(int) $a->getId() => 'refused', (int) $c->getId() => 'confirm'], $plans);
        self::assertStringContainsString('Linked to visit #'.$w, $crawler->filter(sprintf('[data-test="encounter-validation-option"][data-partner="%d"]', (int) $a->getId()))->text());

        $this->client->submit($crawler->filter(sprintf('[data-test="encounter-validation-option"][data-partner="%d"]', (int) $c->getId()))->selectButton('Link this allocation')->form());
        self::assertResponseRedirects();
        self::assertContains(sprintf('%d-%d confirmed', $c->getId(), $v), array_map(static fn (string $row): string => substr($row, 0, strrpos($row, ' ') ?: null), $this->matchRows($this->hospital)));
        self::assertSame(ValidationVerdict::Missed, $this->reload($item)->getVerdict());
    }

    public function testItemWithACandidateClaimedByAnotherAllocationLinksTheRival(): void
    {
        $first = $this->allocationAt($this->hospital, $this->ivena, '12:00');
        $second = $this->allocationAt($this->hospital, $this->ivena, '12:05');
        $this->visitAt($this->hospital, $this->ed, '12:10');
        $this->matchDay($this->hospital);

        $validator = self::getContainer()->get(MatchValidator::class);
        self::assertInstanceOf(MatchValidator::class, $validator);
        $sample = $validator->draw((int) $this->hospital->getId(), new ReviewPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')), 5, $this->reviewer, 1);
        /** @var list<MatchValidationItem> $items */
        $items = $this->encounterEntityManager()->getRepository(MatchValidationItem::class)->findBy(['sample' => $sample]);
        $items = array_values(array_filter($items, static fn (MatchValidationItem $item): bool => \in_array($item->getAllocation()?->getId(), [$first->getId(), $second->getId()], true)));
        self::assertNotEmpty($items);

        $this->client->loginUser($this->reviewer);
        $rivalLinks = [];
        foreach ($items as $item) {
            $crawler = $this->client->request(Request::METHOD_GET, $this->validationUrl().'/'.$sample->getId().'/items/'.$item->getId());
            self::assertResponseIsSuccessful();
            $rivalLinks = [...$rivalLinks, ...$crawler->filter('[data-test="encounter-review-rivals"] a')->each(static fn (Crawler $link): string => (string) $link->attr('href'))];
        }

        // The rival links to the review of the other allocation, in the period of the sample.
        self::assertNotEmpty($rivalLinks, 'The visit is a candidate of both allocations.');
        foreach ($rivalLinks as $href) {
            self::assertStringEndsWith('?from=2026-10-01&to=2026-10-31', $href);
        }
    }

    /**
     * Allocations 14:00 and 15:00 without visit, visit 18:00 by ambulance without allocation: three items
     * without link (positions 1–3). "No partner" records its verdict; "skip" records nothing and opens
     * the next open item after this one, wrapping around, or the sample when no other item is open.
     */
    public function testSkipLeavesItemsOpenAndOpensTheNextOpenItem(): void
    {
        $this->allocationAt($this->hospital, $this->ivena, '14:00');
        $this->allocationAt($this->hospital, $this->ivena, '15:00');
        $this->visitAt($this->hospital, $this->ed, '18:00');
        $sample = $this->drawEmpty($this->hospital);
        [$first, $second, $third] = $this->encounterEntityManager()->getRepository(MatchValidationItem::class)->findBy(['sample' => $sample], ['position' => 'ASC']);
        $sampleUrl = $this->validationUrl().'/'.$sample->getId();

        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, $this->itemUrl($sample, $first));
        self::assertResponseIsSuccessful();
        // Unclear, no partner, then skip.
        self::assertSame(
            ['encounter-validation-verdict-unclear', 'encounter-validation-verdict-no_partner', 'encounter-validation-skip'],
            $crawler->filter('[data-test="encounter-validation-decision"] .btn')->each(static fn (Crawler $button): string => (string) $button->attr('data-test')),
        );
        self::assertSelectorTextContains('[data-test="encounter-validation-skip-note"]', 'nothing is recorded');

        // Skip on item 1 opens item 2 and records nothing.
        $crawler = $this->client->click($crawler->filter('[data-test="encounter-validation-skip"]')->link());
        self::assertSame($this->itemUrl($sample, $second), parse_url($this->client->getRequest()->getUri(), \PHP_URL_PATH));
        self::assertNull($this->reload($first)->getVerdict());

        // Skip on item 3 wraps around to item 1, the first open item.
        $crawler = $this->client->request(Request::METHOD_GET, $this->itemUrl($sample, $third));
        $crawler = $this->client->click($crawler->filter('[data-test="encounter-validation-skip"]')->link());
        self::assertSame($this->itemUrl($sample, $first), parse_url($this->client->getRequest()->getUri(), \PHP_URL_PATH));

        // "No partner" records its verdict and opens the next open item.
        $crawler = $this->verdict($crawler, 'no_partner');
        self::assertSame(ValidationVerdict::NoPartner, $this->reload($first)->getVerdict());
        self::assertSame($this->itemUrl($sample, $second), parse_url($this->client->getRequest()->getUri(), \PHP_URL_PATH));
        $this->verdict($crawler, 'unclear');
        self::assertSame(ValidationVerdict::Unclear, $this->reload($second)->getVerdict());

        // Item 3 is the last open item: skip returns to the sample and leaves it open.
        $crawler = $this->client->request(Request::METHOD_GET, $this->itemUrl($sample, $third));
        $this->client->click($crawler->filter('[data-test="encounter-validation-skip"]')->link());
        self::assertSame($sampleUrl, parse_url($this->client->getRequest()->getUri(), \PHP_URL_PATH));
        self::assertNull($this->reload($third)->getVerdict());
    }

    public function testSameSeedDrawsTheSameCases(): void
    {
        foreach (['13:00', '14:00', '15:00', '16:00', '17:00'] as $arrival) {
            $this->allocationAt($this->hospital, $this->ivena, $arrival);
        }

        $validator = self::getContainer()->get(MatchValidator::class);
        self::assertInstanceOf(MatchValidator::class, $validator);
        $period = new ReviewPeriod(new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12'));
        $first = $validator->draw((int) $this->hospital->getId(), $period, 2, $this->reviewer, 42);
        $second = $validator->draw((int) $this->hospital->getId(), $period, 2, $this->reviewer, 42);

        self::assertSame(['unlinked_allocation' => 5, 'unlinked_visit' => 0], $first->getPopulation());
        self::assertCount(2, $this->allocationsOf($first));
        self::assertSame($this->allocationsOf($first), $this->allocationsOf($second));
    }

    public function testAccessNeedsTheReviewGrantOfTheHospital(): void
    {
        $sample = $this->drawEmpty($this->hospital);
        $url = $this->validationUrl().'/'.$sample->getId();

        // Case access without review: 403.
        $viewer = $this->user();
        $this->hospitalGrant($this->hospital, $viewer);
        $this->encounterGrant($this->hospital, $viewer, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($viewer);
        $this->client->request(Request::METHOD_GET, $this->validationUrl());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // A reviewer of another hospital: this sample under their own hospital is not found.
        $other = $this->hospital($this->user());
        $otherReviewer = $this->user();
        $this->hospitalGrant($other, $otherReviewer);
        $this->encounterGrant($other, $otherReviewer, [EncounterGrantAction::Review]);
        $this->client->loginUser($otherReviewer);
        $this->client->request(Request::METHOD_GET, sprintf('/tools/encounter-review/%d/validation/%d', (int) $other->getId(), (int) $sample->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Administrators see every hospital without a grant.
        $this->client->loginUser($this->admin());
        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
    }

    private function verdict(Crawler $crawler, string $verdict): Crawler
    {
        $this->client->submit($crawler->filter('[data-test="encounter-validation-verdict-'.$verdict.'"]')->form());
        self::assertResponseRedirects();

        return $this->client->followRedirect();
    }

    private function itemOf(MatchValidationSample $sample, ValidationStratum $stratum): MatchValidationItem
    {
        $item = $this->encounterEntityManager()->getRepository(MatchValidationItem::class)->findOneBy(['sample' => $sample, 'stratum' => $stratum]);
        self::assertInstanceOf(MatchValidationItem::class, $item, $stratum->value);

        return $item;
    }

    private function reload(MatchValidationItem $item): MatchValidationItem
    {
        $this->encounterEntityManager()->clear();
        $fresh = $this->encounterEntityManager()->find(MatchValidationItem::class, $item->getId());
        self::assertInstanceOf(MatchValidationItem::class, $fresh);

        return $fresh;
    }

    private function itemUrl(MatchValidationSample $sample, MatchValidationItem $item): string
    {
        return $this->validationUrl().'/'.$sample->getId().'/items/'.$item->getId();
    }

    /**
     * Cells of one report row by their data-test suffix (`report-<name>`).
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function reportCells(Crawler $crawler, string $row, array $names): array
    {
        $line = $crawler->filter('[data-test="encounter-validation-sample-report"] [data-test="'.$row.'"]');
        self::assertCount(1, $line, $row);

        return array_map(static fn (string $name): string => str_replace("\u{a0}", '', trim($line->filter('[data-test="report-'.$name.'"]')->text())), $names);
    }

    /**
     * @return list<int>
     */
    private function allocationsOf(MatchValidationSample $sample): array
    {
        /** @var list<MatchValidationItem> $items */
        $items = $this->encounterEntityManager()->getRepository(MatchValidationItem::class)->findBy(['sample' => $sample], ['position' => 'ASC']);

        return array_map(static fn (MatchValidationItem $item): int => (int) $item->getAllocation()?->getId(), $items);
    }

    private function drawEmpty(Hospital $hospital): MatchValidationSample
    {
        $validator = self::getContainer()->get(MatchValidator::class);
        self::assertInstanceOf(MatchValidator::class, $validator);

        return $validator->draw((int) $hospital->getId(), new ReviewPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')), 5, $this->reviewer, 1);
    }

    private function sample(): MatchValidationSample
    {
        $sample = $this->encounterEntityManager()->getRepository(MatchValidationSample::class)->findOneBy(['hospital' => $this->hospital->getId()]);
        self::assertInstanceOf(MatchValidationSample::class, $sample);

        return $sample;
    }

    /**
     * Metadata of the latest verdict audit entry.
     *
     * @return array<string, mixed>
     */
    private function validationAudit(): array
    {
        $entries = array_values(array_filter(
            $this->encounterEntityManager()->getRepository(AuditEntry::class)->findBy(['entityClass' => MatchValidationItem::class], ['id' => 'DESC']),
            static fn (AuditEntry $entry): bool => ($entry->getMetadata()['intent'] ?? null) === MatchValidator::INTENT_VERDICT,
        ));
        self::assertNotEmpty($entries);

        /* @var array<string, mixed> */
        return $entries[0]->getMetadata()['intent_metadata'] ?? $entries[0]->getMetadata();
    }

    private function validationUrl(): string
    {
        return '/tools/encounter-review/'.$this->hospital->getId().'/validation';
    }
}
