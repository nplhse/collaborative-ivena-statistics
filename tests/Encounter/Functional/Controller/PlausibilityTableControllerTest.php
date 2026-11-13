<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Plausibility\PlausibilitySnapshots;
use App\Encounter\Domain\Entity\PlausibilityProblemArea;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\PlausibilitySnapshotStatus;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Review pages of the plausibility table (ADR 033), a tool under /tools (ADR 031): only for
 * ROLE_ENCOUNTER_CATALOG_REVIEWER together with ROLE_ENCOUNTER_BETA (administrators inherit both); points, sources and
 * changes, never counts; editing rebuilds the draft; activation is audited.
 */
#[ResetDatabase]
final class PlausibilityTableControllerTest extends WebTestCase
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

    public function testOnlyReviewersWithTheBetaRoleAndAdminsOpenThePages(): void
    {
        $draftId = $this->seedDraft()->getId();
        $paths = ['/tools/encounter-plausibility', '/tools/encounter-plausibility/areas/urologie/edit', '/tools/encounter-plausibility/areas/new', '/tools/encounter-plausibility/snapshots/'.$draftId];

        $cases = [
            'beta user without the reviewer role' => [$this->user(), 403],
            'reviewer without the beta role' => [$this->user(['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_CATALOG_REVIEWER']), 403],
            'reviewer with the beta role' => [$this->user(self::REVIEWER), 200],
            'administrator' => [$this->admin(), 200],
        ];
        foreach ($cases as $case => [$user, $status]) {
            $this->client->loginUser($user);
            foreach ($paths as $path) {
                $this->client->request(Request::METHOD_GET, $path);
                self::assertResponseStatusCodeSame($status, $case.' '.$path);
            }
        }

        $this->client->loginUser($this->user());
        $this->client->request(Request::METHOD_POST, '/tools/encounter-plausibility/snapshots/'.$draftId.'/activate');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(PlausibilitySnapshotStatus::Draft, $this->seedDraft()->getStatus());
    }

    public function testOverviewListsTheSeedAndTheToolsPageLinksIt(): void
    {
        IndicationNormalizedFactory::createOne(['code' => 711, 'name' => 'Nieren- Harnleiterkolik']);
        IndicationNormalizedFactory::createOne(['code' => 779, 'name' => 'sonstige Notfallsituationen']);
        $this->client->loginUser($this->user(self::REVIEWER));
        $this->client->enableProfiler();

        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-plausibility', server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        $this->assertNoMissingTranslations($this->client->getProfile());
        self::assertCount(24, $crawler->filter('[data-test="encounter-plausibility-area"]'));
        self::assertCount(1, $crawler->filter('[data-test="encounter-plausibility-snapshot"] [data-status="draft"]'));
        $urology = $crawler->filter('[data-area="urologie"]');
        self::assertStringContainsString('711', $urology->text());
        self::assertSame(['abdominelle_schmerzen_bei_erwachsenen', 'hodenschmerz', 'rueckenschmerz', 'urologisches_problem'], $urology->filter('[data-test="encounter-plausibility-rule"]')->extract(['data-diagram']));
        self::assertCount(1, $urology->filter('[title="Nieren- Harnleiterkolik"]'), 'PZC names from the stored indications');
        self::assertSame('779 sonstige Notfallsituationen', trim($crawler->filter('[data-test="encounter-plausibility-unassigned"] .badge')->text()), 'stored codes without an area');

        $tools = $this->client->request(Request::METHOD_GET, '/tools');
        self::assertCount(1, $tools->filter('[data-tool-key="encounter_plausibility"][href="/tools/encounter-plausibility"]'));

        $this->client->loginUser($this->user());
        $tools = $this->client->request(Request::METHOD_GET, '/tools');
        self::assertCount(0, $tools->filter('[data-tool-key="encounter_plausibility"]'), 'not for beta users without the reviewer role');
        self::assertCount(1, $tools->filter('[data-tool-key="encounter_review"]'));
    }

    public function testReviewShowsPointsAndChangesButNoCounts(): void
    {
        $this->client->loginUser($this->user(self::REVIEWER));
        $seed = $this->seedDraft();
        $this->post('/tools/encounter-plausibility/snapshots/'.$seed->getId().'/activate', 'encounter_plausibility_snapshot_activate_'.$seed->getId());
        self::assertResponseRedirects('/tools/encounter-plausibility/snapshots/'.$seed->getId());
        self::assertSame(1, $this->auditRows('encounter.plausibility.snapshot_activated'));

        // A learned draft with distinctive counts: they must not appear anywhere on the page.
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("INSERT INTO encounter_plausibility_snapshot (status, origin, created_at) VALUES ('draft', 'learned', LOCALTIMESTAMP(0))");
        $learnedId = (int) $connection->fetchOne("SELECT id FROM encounter_plausibility_snapshot WHERE origin = 'learned'");
        $connection->executeStatement('INSERT INTO encounter_plausibility_snapshot_code (snapshot_id, pzc, area_code) SELECT ?, pzc, area_code FROM encounter_plausibility_snapshot_code WHERE snapshot_id = ?', [$learnedId, $seed->getId()]);
        $connection->executeStatement('INSERT INTO encounter_plausibility_cell (snapshot_id, pzc, area_code, diagram_code, indicator_code, points, source, positives, negatives) SELECT ?, pzc, area_code, diagram_code, NULL, points, source, 0, 0 FROM encounter_plausibility_cell WHERE snapshot_id = ?', [$learnedId, $seed->getId()]);
        // 711 × urologisches_problem learned +1 from 4711 + 3141 observations; 712 × hodenschmerz learned +1 from 7 (below the minimum).
        $connection->executeStatement("UPDATE encounter_plausibility_cell SET points = 1, source = 'learned', positives = 4711, negatives = 3141 WHERE snapshot_id = ? AND pzc = 711 AND diagram_code = 'urologisches_problem'", [$learnedId]);
        $connection->executeStatement("UPDATE encounter_plausibility_cell SET points = 1, source = 'learned', positives = 4, negatives = 3 WHERE snapshot_id = ? AND pzc = 712 AND diagram_code = 'hodenschmerz'", [$learnedId]);
        $connection->executeStatement("UPDATE encounter_plausibility_cell SET points = 2, source = 'learned', positives = 2222, negatives = 1111 WHERE snapshot_id = ? AND area_code = 'urologie' AND diagram_code = 'rueckenschmerz'", [$learnedId]);

        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-plausibility/snapshots/'.$learnedId);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        foreach (['4711', '3141', '7852', '2222', '1111', '3333'] as $count) {
            self::assertStringNotContainsString($count, $content, 'no count on the review page');
        }

        $changed = $crawler->filter('[data-test="encounter-plausibility-cell"][data-changed="1"]');
        self::assertSame(['urologie|rueckenschmerz'], $changed->each(static fn ($row): string => $row->attr('data-area').'|'.$row->attr('data-diagram')));
        self::assertStringContainsString('+2', $changed->text());
        self::assertStringContainsString('+3', $changed->text());

        $pairs = $crawler->filter('[data-test="encounter-plausibility-pair-change"]');
        $keys = $pairs->each(static fn ($row): string => $row->attr('data-pzc').'|'.$row->attr('data-diagram'));
        self::assertContains('711|urologisches_problem', $keys, 'learned value with enough observations');
        self::assertNotContains('712|hodenschmerz', $keys, 'below the minimum the area value applies: no change');
        self::assertContains('263|rueckenschmerz', $keys, 'area value +2 instead of +3 for every urology code');
        self::assertCount(1 + 9, $keys, '711 × urologisches_problem and the nine urology codes × rueckenschmerz');
    }

    public function testEditingAnAreaRebuildsTheDraftAndRefusesATakenCode(): void
    {
        $this->client->loginUser($this->user(self::REVIEWER));
        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-plausibility/areas/new');
        $form = $crawler->filter('form[name="plausibility_area"]')->form();
        $form['plausibility_area[germanName]'] = 'Augen';
        $form['plausibility_area[pzcCodes]'] = '721, 711';
        $form['plausibility_area[diagrams][gesichtsprobleme]'] = '2';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('PZC 711 already belongs to “Urologie”', html_entity_decode((string) $this->client->getResponse()->getContent()));

        $form['plausibility_area[pzcCodes]'] = '721, Auge';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('“Auge” is not a PZC code or range.', html_entity_decode((string) $this->client->getResponse()->getContent()));

        $form['plausibility_area[pzcCodes]'] = '721-722';
        $this->client->submit($form);
        self::assertResponseRedirects('/tools/encounter-plausibility');

        $repository = self::getContainer()->get(PlausibilityRepositoryInterface::class);
        $area = $repository->findAreaByCode('augen');
        self::assertNotNull($area);
        self::assertSame([721, 722], $area->pzcCodes());
        self::assertSame(['gesichtsprobleme' => 2], $area->diagramPoints());
        $draftId = (int) $this->seedDraft()->getId();
        self::assertSame(2, (int) self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM encounter_plausibility_cell WHERE snapshot_id = ? AND diagram_code = 'gesichtsprobleme' AND pzc IN (721, 722) AND points = 2", [$draftId]));
    }

    public function testDeletingAnAreaRebuildsTheDraftAndKeepsTheActiveSnapshot(): void
    {
        $this->client->loginUser($this->user(self::REVIEWER));
        $seed = $this->seedDraft();
        $this->post('/tools/encounter-plausibility/snapshots/'.$seed->getId().'/activate', 'activate');
        $this->editUrologyRules();
        $draftId = (int) $this->seedDraft()->getId();
        self::assertNotSame($seed->getId(), $draftId);

        $this->client->request(Request::METHOD_POST, '/tools/encounter-plausibility/areas/urologie/delete', ['_token' => 'invalid']);
        self::assertResponseRedirects('/tools/encounter-plausibility/areas/urologie/edit');
        self::assertNotNull(self::getContainer()->get(PlausibilityRepositoryInterface::class)->findAreaByCode('urologie'), 'an invalid token deletes nothing');

        $this->client->loginUser($this->user());
        $this->client->request(Request::METHOD_POST, '/tools/encounter-plausibility/areas/urologie/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403, 'beta users without the reviewer role');

        $this->client->loginUser($this->user(self::REVIEWER));
        $this->post('/tools/encounter-plausibility/areas/urologie/delete', 'delete', '/tools/encounter-plausibility/areas/urologie/edit');
        self::assertResponseRedirects('/tools/encounter-plausibility');
        self::assertNull(self::getContainer()->get(PlausibilityRepositoryInterface::class)->findAreaByCode('urologie'));

        $connection = self::getContainer()->get(Connection::class);
        $cells = 'SELECT COUNT(*) FROM encounter_plausibility_cell WHERE snapshot_id = ? AND (area_code = \'urologie\' OR pzc = 711)';
        self::assertSame(0, (int) $connection->fetchOne($cells, [$draftId]), 'the draft no longer has urology cells');
        self::assertSame(8, (int) $connection->fetchOne($cells, [$seed->getId()]), 'the active snapshot keeps its 4 urology area cells and the 4 exact cells of 711');
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE intent = ? AND entity_class = ?', ['encounter.plausibility.area_deleted', PlausibilityProblemArea::class]));

        $this->client->request(Request::METHOD_POST, '/tools/encounter-plausibility/areas/urologie/delete', ['_token' => 'any']);
        self::assertResponseStatusCodeSame(404, 'an unknown area');

        $this->post('/tools/encounter-plausibility/snapshots/'.$draftId.'/delete', 'delete draft');
        self::assertResponseRedirects('/tools/encounter-plausibility');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM encounter_plausibility_snapshot WHERE id = ?', [$draftId]), 'the draft is deleted');
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM encounter_plausibility_snapshot'), 'the active snapshot stays');
    }

    public function testSnapshotChangesAreRefusedWithAnInvalidTokenOrTheWrongStatus(): void
    {
        $this->client->loginUser($this->user(self::REVIEWER));
        $seedId = (int) $this->seedDraft()->getId();
        $page = '/tools/encounter-plausibility/snapshots/'.$seedId;

        $this->client->request(Request::METHOD_POST, $page.'/activate', ['_token' => 'invalid']);
        self::assertResponseRedirects($page);
        self::assertSame(PlausibilitySnapshotStatus::Draft, $this->seedDraft()->getStatus(), 'an invalid token changes nothing');

        $this->client->request(Request::METHOD_POST, $page.'/archive', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(405, 'an unknown action has no POST route (only the catch-all content page answers GET)');

        // A stale page: someone else activates the draft between loading the page and submitting its form.
        $crawler = $this->client->request(Request::METHOD_GET, $page);
        $activate = $crawler->filter(\sprintf('form[action="%s/activate"]', $page))->form();
        $this->activateElsewhere($seedId);
        $this->client->submit($activate);
        self::assertResponseRedirects($page);
        self::assertStringContainsString('Snapshot #'.$seedId.' is not a draft.', $this->client->followRedirect()->filter('body')->text());

        $crawler = $this->client->request(Request::METHOD_GET, $page);
        $retire = $crawler->filter(\sprintf('form[action="%s/retire"]', $page))->form();
        $this->client->submit($retire);
        self::assertSame(PlausibilitySnapshotStatus::Retired, $this->seedDraft()->getStatus());
        $this->client->submit($retire);
        self::assertStringContainsString('Snapshot #'.$seedId.' is not active.', $this->client->followRedirect()->filter('body')->text(), 'retiring twice');
    }

    private function seedDraft(): PlausibilitySnapshot
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $draft = self::getContainer()->get(PlausibilityRepositoryInterface::class)->snapshots()[0] ?? null;
        self::assertInstanceOf(PlausibilitySnapshot::class, $draft);

        return $draft;
    }

    private function post(string $path, string $tokenId, ?string $page = null): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, $page ?? substr($path, 0, (int) strrpos($path, '/')));
        $token = $crawler->filter(\sprintf('form[action="%s"] input[name="_token"]', $path))->attr('value');
        self::assertNotNull($token, $tokenId);
        $this->client->request(Request::METHOD_POST, $path, ['_token' => $token]);
    }

    private function activateElsewhere(int $snapshotId): void
    {
        $snapshot = self::getContainer()->get(EntityManagerInterface::class)->find(PlausibilitySnapshot::class, $snapshotId);
        self::assertInstanceOf(PlausibilitySnapshot::class, $snapshot);
        self::getContainer()->get(PlausibilitySnapshots::class)->activate($snapshot);
    }

    private function editUrologyRules(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-plausibility/areas/urologie/edit');
        $form = $crawler->filter('form[name="plausibility_area"]')->form();
        $form['plausibility_area[diagrams][hodenschmerz]'] = '';
        $this->client->submit($form);
        self::assertResponseRedirects('/tools/encounter-plausibility');
    }

    private function auditRows(string $intent): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM audit_log WHERE intent = ? AND entity_class = ?', [$intent, PlausibilitySnapshot::class]);
    }
}
