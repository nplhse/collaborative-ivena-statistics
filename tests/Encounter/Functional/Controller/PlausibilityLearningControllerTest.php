<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\PlausibilitySnapshotOrigin;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Learning job on the plausibility tool pages (ADR 033, #1155): only catalog reviewers with the beta role and
 * administrators run it; the report shows replay figures only for hospitals on which the viewer holds the encounter
 * grant REVIEW (administrators: every hospital and the sum), never the count over all hospitals to a reviewer.
 */
#[ResetDatabase]
final class PlausibilityLearningControllerTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use EncounterTestFixtures;
    use Factories;

    private const array REVIEWER = ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA', 'ROLE_ENCOUNTER_CATALOG_REVIEWER'];

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testOnlyReviewersWithTheBetaRoleAndAdminsRunTheLearningJob(): void
    {
        $snapshots = $this->snapshotCount();
        foreach (['beta user without the reviewer role' => $this->user(), 'reviewer without the beta role' => $this->user(['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_CATALOG_REVIEWER'])] as $case => $user) {
            $this->client->loginUser($user);
            $this->client->request(Request::METHOD_POST, '/tools/encounter-plausibility/learn');
            self::assertResponseStatusCodeSame(403, $case);
        }

        foreach ([$this->user(self::REVIEWER), $this->admin()] as $user) {
            $this->client->loginUser($user);
            $this->learn();
            self::assertResponseRedirects('/tools/encounter-plausibility');
            $crawler = $this->client->followRedirect();
            self::assertStringContainsString('no confirmed pairs or automatic links', implode(' ', $crawler->filter('.alert')->each(static fn (Crawler $alert): string => $alert->text())), 'nothing to learn from in an empty database');
        }

        $this->client->loginUser($this->user(self::REVIEWER));
        $this->client->request(Request::METHOD_POST, '/tools/encounter-plausibility/learn', ['_token' => 'wrong']);
        self::assertResponseRedirects('/tools/encounter-plausibility');
        self::assertSame($snapshots, $this->snapshotCount(), 'no snapshot without eligible pairs or with a wrong token');
    }

    public function testAReviewerSeesTheReplayOfReviewedHospitalsOnly(): void
    {
        $reviewer = $this->user(self::REVIEWER);
        $reviewed = $this->hospital(null);
        $viewedOnly = $this->hospital(null);
        $this->encounterGrant($reviewed, $reviewer, [EncounterGrantAction::Review]);
        $this->encounterGrant($viewedOnly, $reviewer, [EncounterGrantAction::ViewCases]);
        $snapshotId = $this->learnedDraft([(int) $reviewed->getId() => 4101, (int) $viewedOnly->getId() => 5202]);

        $this->client->loginUser($reviewer);
        $this->client->enableProfiler();
        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-plausibility/snapshots/'.$snapshotId, server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        $this->assertNoMissingTranslations($this->client->getProfile());
        self::assertSame([(string) $reviewed->getId()], $this->replayHospitals($crawler));
        self::assertSame(['none', 'active', 'draft', 'cross_fitted'], $crawler->filter('[data-test="encounter-plausibility-replay-row"]')->extract(['data-variant']));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('4101', $content, 'figures of the reviewed hospital');
        foreach (['5202', '9303', '7777'] as $count) {
            self::assertStringNotContainsString($count, $content, 'no figure of another hospital and no count over all hospitals');
        }

        self::assertCount(0, $crawler->filter('[data-test="encounter-plausibility-learning-total-eligible"]'));
        self::assertStringContainsString('Glättung 1, Schrumpfung zum Seed 10, Mindestbeobachtungen 10', $crawler->filter('[data-test="encounter-plausibility-learning-parameters"]')->text());
    }

    public function testAReviewerWithoutReviewGrantSeesNoReplayAndAnAdminSeesAll(): void
    {
        $first = $this->hospital(null);
        $second = $this->hospital(null);
        $snapshotId = $this->learnedDraft([(int) $first->getId() => 4101, (int) $second->getId() => 5202]);

        $this->client->loginUser($this->user(self::REVIEWER));
        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-plausibility/snapshots/'.$snapshotId);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->replayHospitals($crawler));
        self::assertCount(1, $crawler->filter('[data-test="encounter-plausibility-learning-no-replay"]'));
        self::assertStringNotContainsString('4101', (string) $this->client->getResponse()->getContent());

        $this->client->loginUser($this->admin());
        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-plausibility/snapshots/'.$snapshotId);
        self::assertResponseIsSuccessful();
        $hospitals = $this->replayHospitals($crawler);
        sort($hospitals);
        $expected = [(string) $first->getId(), (string) $second->getId(), 'all'];
        sort($expected);
        self::assertSame($expected, $hospitals);
        // Sum for administrators: 4101 + 5202 = 9303 allocations without bonus; 7777 eligible pairs stored on the snapshot
        // is not shown, the page adds up the hospitals' own counts (11 + 22 = 33).
        self::assertStringContainsString('9303', $crawler->filter('[data-test="encounter-plausibility-replay"][data-hospital="all"] + .table-responsive')->text());
        self::assertStringContainsString('33', $crawler->filter('[data-test="encounter-plausibility-learning-total-eligible"]')->text());
    }

    private function learn(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-plausibility');
        $token = $crawler->filter('[data-test="encounter-plausibility-learn"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $this->client->request(Request::METHOD_POST, '/tools/encounter-plausibility/learn', ['_token' => $token]);
    }

    /**
     * A learned draft whose report has, per hospital, the given number of allocations in every variant and distinctive
     * eligible counts (11 and 22); the snapshot's own count over all hospitals is 7777.
     *
     * @param array<int, int> $allocationsByHospital
     */
    private function learnedDraft(array $allocationsByHospital): int
    {
        $hospitals = [];
        $eligible = 11;
        foreach ($allocationsByHospital as $hospitalId => $allocations) {
            $outcome = ['allocations' => $allocations, 'automatic' => 1, 'proposal' => 0, 'none' => $allocations - 1, 'decisive' => 0, 'agree' => 0, 'disagree' => 0];
            $hospitals[$hospitalId] = ['eligible_pairs' => $eligible, 'eligible_from_bonus' => 0, 'variants' => ['none' => $outcome, 'active' => $outcome, 'draft' => $outcome, 'cross_fitted' => $outcome]];
            $eligible += 11;
        }

        $snapshot = new PlausibilitySnapshot(PlausibilitySnapshotOrigin::Learned, new \DateTimeImmutable('2026-11-14 09:00'));
        $snapshot->recordLearning(
            new \DateTimeImmutable('2026-10-01'),
            new \DateTimeImmutable('2026-10-31'),
            7777,
            ['smoothing' => 1.0, 'shrinkage' => 10.0, 'min_observations' => 10, 'fold' => 'alternating_days'],
            ['active_snapshot_id' => null, 'hospitals' => $hospitals],
        );
        $this->entityManager()->persist($snapshot);
        $this->entityManager()->flush();

        return (int) $snapshot->getId();
    }

    /**
     * @return list<string>
     */
    private function replayHospitals(Crawler $crawler): array
    {
        return array_values(array_map(strval(...), $crawler->filter('[data-test="encounter-plausibility-replay"]')->extract(['data-hospital'])));
    }

    private function snapshotCount(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM encounter_plausibility_snapshot');
    }
}
