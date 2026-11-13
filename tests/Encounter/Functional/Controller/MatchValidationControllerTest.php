<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Entity\MatchValidationSample;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\EncounterGrantAction;
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
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Match validation (#1057): drawing a stratified sample, verdicts (a wrong automatic link is rejected),
 * the report per score band and access.
 *
 * Data on 12 October 2026 (MatchingDataFixtures: allocations female, 70 years; visits by ambulance):
 * - 10:00 → visit 10:05: 9 points, 512 / 528 = 0.9697, automatic link, band 0.90–0.99
 * - 12:00 → visit 12:20 at age 71: 2 + 2 + 1 + 1 = 6 points, 64 / 80 = 0.80, proposal, band 0.70–0.90
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
        self::assertSelectorTextContains('[data-test="encounter-validation-sample-meta"]', 'Matcher 1.0.0');

        // Judged of sampled and the population when drawn, in the summary column.
        self::assertSame(['0 of 1 judged', '1 in period'], $this->reportCells($crawler, 'report-automatic-0.90–0.99', ['progress', 'population']));
        self::assertSame(['0 of 1 judged', '1 in period'], $this->reportCells($crawler, 'report-proposal-0.70–0.90', ['progress', 'population']));
        self::assertSame(['0 of 1 judged', '2 in period'], $this->reportCells($crawler, 'report-unlinked_allocation', ['progress', 'population']));
        self::assertSame(['0 of 1 judged', '1 in period'], $this->reportCells($crawler, 'report-unlinked_visit', ['progress', 'population']));
        self::assertCount(4, $crawler->filter('[data-test="encounter-validation-item"]'));
        // Drawing changes no link.
        self::assertSame($before, $this->matchRows($this->hospital));

        // Case 1, the automatic link: its origin is shown; "wrong" rejects it.
        $crawler = $this->client->click($crawler->filter('[data-test="encounter-validation-continue"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-validation-origin"]', 'Sampled as Automatic link, agreement 0.97 (band 0.90–0.99), pass 1, matcher 1.0.0');
        self::assertSame((string) $linkedVisit, $crawler->filter('[data-test="encounter-validation-candidate"]')->first()->attr('data-encounter'));
        $crawler = $this->verdict($crawler, 'wrong');
        self::assertSelectorTextContains('.alert-success', 'the pair was rejected');
        self::assertContains(sprintf('%d-%d rejected 0.9697', $linked->getId(), $linkedVisit), $this->matchRows($this->hospital));
        self::assertSame('wrong', $this->validationAudit()['verdict']);
        self::assertTrue($this->validationAudit()['pair_rejected']);

        // Case 2, the proposal: "correct" records the verdict only; the proposal stays open.
        self::assertSelectorTextContains('[data-test="encounter-validation-origin"]', 'band 0.70–0.90');
        $crawler = $this->verdict($crawler, 'correct');
        self::assertContains(sprintf('%d-%d proposed 0.8000', $proposed->getId(), $proposedVisit), $this->matchRows($this->hospital));

        // Case 3, an allocation without link: no candidate in the review window.
        self::assertSelectorTextContains('h2, .page-title', 'Allocation without link');
        $crawler = $this->verdict($crawler, 'no_partner');

        // Case 4, the EMS visit: no allocation in reach.
        self::assertSelectorTextContains('[data-test="encounter-validation-visit"]', 'Visit #'.$emsVisit);
        self::assertSelectorTextContains('[data-test="encounter-validation-nearby"]', 'No allocation in reach');
        $crawler = $this->verdict($crawler, 'missed');
        self::assertStringEndsWith($this->validationUrl().'/'.$this->sample()->getId(), $this->client->getRequest()->getUri());
        self::assertSelectorTextContains('.alert-info', 'Every case of the sample has a verdict');

        // Verdicts and share per entry.
        self::assertSame(['0 correct · 1 wrong', '0%'], $this->reportCells($crawler, 'report-automatic-0.90–0.99', ['counts', 'share-value']));
        self::assertSame(['1 correct · 0 wrong', '100%'], $this->reportCells($crawler, 'report-proposal-0.70–0.90', ['counts', 'share-value']));
        self::assertSame(['0 missed · 1 no partner', '0%'], $this->reportCells($crawler, 'report-unlinked_allocation', ['counts', 'share-value']));
        self::assertSame(['1 missed · 0 no partner', '100%'], $this->reportCells($crawler, 'report-unlinked_visit', ['counts', 'share-value']));
        // Cases left, summary right.
        self::assertCount(1, $crawler->filter('.col-lg-8 [data-test="encounter-validation-items"]'));
        self::assertCount(3, $crawler->filter('.col-lg-4 [data-test="encounter-validation-sample-report"] .datagrid'), 'automatic, proposals, without link');
        self::assertSelectorTextContains('[data-test="weighted-automatic"]', '0%');
        self::assertSelectorTextContains('[data-test="encounter-validation-report-note"]', 'not a hit probability');

        // The overview adds up every sample per matcher version.
        $this->client->request(Request::METHOD_GET, $this->validationUrl());
        self::assertSelectorExists('[data-test="encounter-validation-combined"][data-version="1.0.0"]');
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
        $this->client->request(Request::METHOD_GET, sprintf('/encounter/review/%d/validation/%d', (int) $other->getId(), (int) $sample->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Administrators see every hospital without a grant.
        $this->client->loginUser($this->admin());
        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
    }

    private function verdict(Crawler $crawler, string $verdict): Crawler
    {
        $form = $crawler->selectButton('Save verdict')->form();
        $choice = $form['validation_verdict[verdict]'];
        self::assertInstanceOf(ChoiceFormField::class, $choice);
        $choice->select($verdict);
        $this->client->submit($form);
        self::assertResponseRedirects();

        return $this->client->followRedirect();
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
        return '/encounter/review/'.$this->hospital->getId().'/validation';
    }
}
