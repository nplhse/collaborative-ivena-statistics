<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller\Admin;

use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
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
 * Back-office maintenance of the MTS catalog (ADR 032): admins only, codes derived on creation, collisions shown on
 * the form, merge and undo, aliases, assignments.
 */
#[ResetDatabase]
final class MtsCatalogAdminTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use EncounterTestFixtures;
    use Factories;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testOnlyAdminsMaintainTheCatalog(): void
    {
        $this->client->loginUser($this->user());
        foreach (['mts-diagrams', 'mts-indicators', 'mts-aliases', 'mts-assignments'] as $path) {
            $this->client->request(Request::METHOD_GET, '/admin/encounter/'.$path);
            self::assertResponseStatusCodeSame(403, $path);
        }
    }

    public function testAdminListsTheSeededCatalogAndTheMenuLinksIt(): void
    {
        $this->client->loginUser($this->admin());
        $this->client->enableProfiler();

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/encounter/mts-diagrams', server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        $this->assertNoMissingTranslations($this->client->getProfile());
        self::assertCount(40, $crawler->filter('table tbody tr'));
        self::assertStringContainsString('Thoraxschmerz', $crawler->filter('table')->text());
        self::assertStringContainsString('Kuratiert', $crawler->filter('table')->text());
        foreach (['mts-diagrams', 'mts-indicators', 'mts-aliases', 'mts-assignments'] as $path) {
            self::assertCount(1, $crawler->filter(\sprintf('nav a[href$="/admin/encounter/%s"]', $path)), $path);
        }
    }

    public function testNewEntryGetsItsCodeAndACollisionIsShownOnTheForm(): void
    {
        $this->client->loginUser($this->admin());

        $this->submitNewDiagram('Augenprobleme bei Kindern', 'Eye problems in children');
        self::assertResponseRedirects();
        $diagram = $this->diagramRow('augenprobleme_bei_kindern');
        self::assertSame(['Augenprobleme bei Kindern', 'Eye problems in children', 'manual', 'curated'], $diagram);

        $this->submitNewDiagram('Stuerze', null);
        self::assertResponseStatusCodeSame(422);
        $content = html_entity_decode((string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Code collision: “Stuerze” gives the code stuerze, which already belongs to “Stürze”', $content);
        self::assertNull($this->diagramRow('stuerze_2'));
        self::assertSame(41, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_mts_diagram'));
    }

    public function testMergeAndUndoOfAProvisionalEntry(): void
    {
        $this->client->loginUser($this->admin());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $provisional = new MtsDiagram('brustenge', 'Brustenge', MtsCatalogSource::FirstSeen, MtsCatalogStatus::Provisional);
        $entityManager->persist($provisional);
        $entityManager->flush();
        $id = $provisional->getId();

        $crawler = $this->client->request(Request::METHOD_GET, \sprintf('/admin/encounter/mts-diagrams/%d/merge', $id));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Merge')->form();
        $form['form[target]'] = 'thoraxschmerz';
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame('merged', $this->connection()->fetchOne('SELECT status FROM encounter_mts_diagram WHERE id = ?', [$id]));
        self::assertSame('thoraxschmerz', $this->connection()->fetchOne("SELECT target_code FROM encounter_mts_alias WHERE kind = 'diagram' AND code = 'brustenge'"));

        $detail = $this->client->request(Request::METHOD_GET, \sprintf('/admin/encounter/mts-diagrams/%d', $id));
        $undo = $detail->filter(\sprintf('form[action*="/admin/encounter/mts-diagrams/%d/undo-merge"]', $id));
        self::assertCount(1, $undo, 'undo is a POST form');
        self::assertCount(0, $detail->filter(\sprintf('a[href$="/admin/encounter/mts-diagrams/%d/merge"]', $id)), 'a merged entry cannot be merged again');
        $this->client->request(Request::METHOD_POST, (string) $undo->attr('action'));

        self::assertResponseRedirects();
        self::assertSame('provisional', $this->connection()->fetchOne('SELECT status FROM encounter_mts_diagram WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_mts_alias'));
    }

    public function testMergeOfACuratedEntryIsRefused(): void
    {
        $this->client->loginUser($this->admin());
        $id = (int) $this->connection()->fetchOne("SELECT id FROM encounter_mts_diagram WHERE code = 'kopfschmerz'");

        $crawler = $this->client->request(Request::METHOD_GET, \sprintf('/admin/encounter/mts-diagrams/%d/merge', $id));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Only provisional entries can be merged', $crawler->filter('.alert-warning')->text());
        self::assertCount(0, $crawler->selectButton('Merge'));
    }

    public function testAliasIsCreatedWithItsDerivedCodeAndAMergeAliasCannotBeEdited(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/encounter/mts-aliases/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="MtsAlias"]')->form();
        $form['MtsAlias[kind]'] = MtsCatalogKind::Diagram->value;
        $form['MtsAlias[label]'] = 'Brustschmerz';
        $form['MtsAlias[targetCode]'] = 'thoraxschmerz';
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame('thoraxschmerz', $this->connection()->fetchOne("SELECT target_code FROM encounter_mts_alias WHERE kind = 'diagram' AND code = 'brustschmerz'"));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $merged = new MtsDiagram('brustenge', 'Brustenge', MtsCatalogSource::FirstSeen, MtsCatalogStatus::Provisional);
        $merged->markMerged();
        $mergeAlias = new MtsAlias(MtsCatalogKind::Diagram, 'brustenge', 'Brustenge', 'thoraxschmerz');
        $entityManager->persist($merged);
        $entityManager->persist($mergeAlias);
        $entityManager->flush();

        $this->client->request(Request::METHOD_GET, \sprintf('/admin/encounter/mts-aliases/%d', $mergeAlias->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(\sprintf('a[href$="/admin/encounter/mts-aliases/%d/edit"]', $mergeAlias->getId()));
        self::assertSelectorNotExists(\sprintf('form[action*="/admin/encounter/mts-aliases/%d/delete"]', $mergeAlias->getId()));
    }

    public function testAssignmentIsCreatedOnce(): void
    {
        $this->client->loginUser($this->admin());
        $diagram = (string) $this->connection()->fetchOne("SELECT id FROM encounter_mts_diagram WHERE code = 'thoraxschmerz'");
        $indicator = (string) $this->connection()->fetchOne("SELECT id FROM encounter_mts_indicator WHERE code = 'heiss'");

        foreach ([302, 422] as $expected) {
            $crawler = $this->client->request(Request::METHOD_GET, '/admin/encounter/mts-assignments/new');
            $form = $crawler->filter('form[name="MtsDiagramIndicator"]')->form();
            $form['MtsDiagramIndicator[diagram]'] = $diagram;
            $form['MtsDiagramIndicator[indicator]'] = $indicator;
            $this->client->submit($form);
            self::assertResponseStatusCodeSame($expected);
        }

        self::assertStringContainsString('heiss is already assigned to thoraxschmerz', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_mts_diagram_indicator'));
    }

    private function submitNewDiagram(string $germanName, ?string $englishName): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/encounter/mts-diagrams/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="MtsDiagram"]')->form();
        $form['MtsDiagram[germanName]'] = $germanName;
        $form['MtsDiagram[englishName]'] = (string) $englishName;
        $form['MtsDiagram[source]'] = MtsCatalogSource::Manual->value;
        $form['MtsDiagram[status]'] = MtsCatalogStatus::Curated->value;
        $this->client->submit($form);
    }

    /**
     * @return list<string|null>|null german name, English name, source, status
     */
    private function diagramRow(string $code): ?array
    {
        $row = $this->connection()->fetchNumeric('SELECT german_name, english_name, source, status FROM encounter_mts_diagram WHERE code = ?', [$code]);

        return false === $row ? null : array_values(array_map(static fn (mixed $value): ?string => null === $value ? null : (string) $value, $row));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
