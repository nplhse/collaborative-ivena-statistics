<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Admin\UI\Http\Controller\Import\ImportCrudController;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportBatchRunItemStatus;
use App\Import\Domain\Enum\ImportBatchRunStatus;
use App\Import\Domain\Enum\ImportExportPeriodSource;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testDeleteEntityRemovesImportViaDeletionService(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $owner = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $import = ImportFactory::createOne([
            'name' => 'Admin Delete Import',
            'hospital' => $hospital,
            'createdBy' => $owner,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
            'filePath' => '/tmp/admin-delete.csv',
        ]);

        $importId = (int) $import->getId();
        $em = $container->get(EntityManagerInterface::class);
        $entity = $em->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $entity);

        $controller = $container->get(ImportCrudController::class);
        $controller->deleteEntity($em, $entity);

        self::assertNull($container->get(ImportRepository::class)->find($importId));
    }

    public function testImportIndexDoesNotOfferNewAction(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'import-index-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/import');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a.action-new'));

        $client->request(Request::METHOD_GET, '/admin/import/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testImportIndexShowsTheStatusAsColouredDotAndTheTypeAsPlainBadge(): void
    {
        $client = self::createClient();
        $hospital = HospitalFactory::createOne();
        ImportFactory::createOne(['hospital' => $hospital, 'name' => 'Failed run', 'type' => ImportType::ALLOCATION, 'status' => ImportStatus::FAILED]);
        ImportFactory::createOne(['hospital' => $hospital, 'name' => 'Partial run', 'type' => ImportType::ALLOCATION, 'status' => ImportStatus::PARTIAL]);
        ImportFactory::createOne(['hospital' => $hospital, 'name' => 'Completed run', 'type' => ImportType::ALLOCATION, 'status' => ImportStatus::COMPLETED]);

        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $crawler = $client->request(Request::METHOD_GET, '/admin/import');
        self::assertResponseIsSuccessful();

        $badges = [];
        foreach ($crawler->filter('tbody tr') as $row) {
            $row = new Crawler($row);
            $badges[trim($row->filter('td.field-text')->first()->text())] = [
                (string) $row->filter('.badge')->eq(0)->attr('class'),
                (string) $row->filter('.badge')->eq(1)->attr('class'),
            ];
        }

        ksort($badges);

        // Status: coloured dot per outcome; type: the default light badge without a variant class.
        self::assertSame([
            'Completed run' => ['badge badge-success badge-variant-dot', 'badge badge-secondary'],
            'Failed run' => ['badge badge-danger badge-variant-dot', 'badge badge-secondary'],
            'Partial run' => ['badge badge-warning badge-variant-dot', 'badge badge-secondary'],
        ], $badges);
    }

    public function testImportDetailShowsCreatedBy(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne([
            'username' => 'import-owner-'.bin2hex(random_bytes(4)),
        ]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
        ]);
        $import = ImportFactory::createOne([
            'name' => 'Admin Detail Import',
            'hospital' => $hospital,
            'createdBy' => $owner,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
            'filePath' => '/tmp/admin-detail.csv',
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'import-detail-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/import/'.$import->getId());
        self::assertResponseIsSuccessful();

        $body = $crawler->text();
        self::assertStringContainsString((string) $owner->getUsername(), $body);
        self::assertStringContainsString('Created by', $body);
        self::assertSelectorExists('a.action-importRejects');
        self::assertSelectorExists('a.action-importBatchItems');

        $jobRuns = $crawler->filter('a.action-importJobRuns');
        self::assertCount(1, $jobRuns);
        $href = urldecode((string) $jobRuns->attr('href'));
        self::assertStringContainsString('/admin/job-run', $href);
        self::assertStringContainsString('filters[importId][value]='.$import->getId(), $href);
    }

    public function testDetailShowsTheTranslatedExportPeriodSource(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create(['locale' => 'de']);
        $import = ImportFactory::createOne([
            'hospital' => HospitalFactory::createOne(),
            'exportPeriodSource' => ImportExportPeriodSource::MANUAL,
        ]);
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/import/'.$import->getId());

        self::assertResponseIsSuccessful();
        $values = $crawler->filter('.field-value')->each(static fn ($node): string => trim($node->text()));
        self::assertContains('Manuell', $values);
        self::assertNotContains('MANUAL', $values);
    }

    public function testEditFormOffersTranslatedExportPeriodSources(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create(['locale' => 'de']);
        $import = ImportFactory::createOne(['hospital' => HospitalFactory::createOne()]);
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/import/'.$import->getId().'/edit');

        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select[name="Import[exportPeriodSource]"] option')
            ->each(static fn ($node): string => trim($node->text()));
        self::assertSame(['Abgeleitet', 'Manuell'], array_values(array_filter($options)));
    }

    public function testEditFormStoresTheSelectedExportPeriodSource(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $import = ImportFactory::createOne([
            'hospital' => HospitalFactory::createOne(),
            'exportPeriodSource' => ImportExportPeriodSource::MANUAL,
        ]);
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/import/'.$import->getId().'/edit');
        $form = $crawler->selectButton('Save changes')->form();
        $form['Import[exportPeriodSource]'] = 'derived';
        $client->submit($form);

        self::assertResponseRedirects();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $stored = $entityManager->find(Import::class, $import->getId());
        self::assertInstanceOf(Import::class, $stored);
        self::assertSame(ImportExportPeriodSource::DERIVED, $stored->getExportPeriodSource());
    }

    public function testIndexShowsTranslatedStatusAndTypeForAGermanAdmin(): void
    {
        $client = self::createClient();
        ImportFactory::createOne(['hospital' => HospitalFactory::createOne(), 'type' => ImportType::CLOSURE, 'status' => ImportStatus::FAILED]);
        $client->loginUser(UserFactory::new()->asAdmin()->create(['locale' => 'de']));

        $crawler = $client->request(Request::METHOD_GET, '/admin/import');

        self::assertResponseIsSuccessful();
        $badges = $crawler->filter('tbody tr .badge')->each(static fn (Crawler $node): string => trim($node->text()));
        self::assertSame(['Fehlgeschlagen', 'Schließung'], $badges);
    }

    public function testDetailShowsTranslatedStatusAndTypeForAGermanAdmin(): void
    {
        $client = self::createClient();
        $import = ImportFactory::createOne(['hospital' => HospitalFactory::createOne(), 'type' => ImportType::ALLOCATION, 'status' => ImportStatus::COMPLETED]);
        $client->loginUser(UserFactory::new()->asAdmin()->create(['locale' => 'de']));

        $crawler = $client->request(Request::METHOD_GET, '/admin/import/'.$import->getId());

        self::assertResponseIsSuccessful();
        $values = $crawler->filter('.field-value')->each(static fn (Crawler $node): string => trim($node->text()));
        self::assertContains('Abgeschlossen', $values);
        self::assertContains('Zuweisung', $values);
        self::assertNotContains('Completed', $values);
        self::assertNotContains('Allocation', $values);
    }

    public function testStatusFilterOffersTranslatedChoicesAndMatchesByValue(): void
    {
        $client = self::createClient();
        $hospital = HospitalFactory::createOne();
        ImportFactory::createOne(['hospital' => $hospital, 'name' => 'Failed run', 'status' => ImportStatus::FAILED]);
        ImportFactory::createOne(['hospital' => $hospital, 'name' => 'Completed run', 'status' => ImportStatus::COMPLETED]);
        $client->loginUser(UserFactory::new()->asAdmin()->create(['locale' => 'de']));

        $crawler = $client->request(Request::METHOD_GET, '/admin/import?filters[status][comparison]=%3D&filters[status][value]=Failed');

        self::assertResponseIsSuccessful();
        $names = $crawler->filter('tbody tr td.field-text')->each(static fn (Crawler $node): string => trim($node->text()));
        self::assertContains('Failed run', $names);
        self::assertNotContains('Completed run', $names);
    }

    public function testFilterFormOffersTranslatedStatusAndTypeChoices(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create(['locale' => 'de']));

        $crawler = $client->request(Request::METHOD_GET, '/admin/import/render-filters');

        self::assertResponseIsSuccessful();
        $status = $crawler->filter('select[name="filters[status][value]"] option')->each(static fn (Crawler $node): string => trim($node->text()));
        $type = $crawler->filter('select[name="filters[type][value]"] option')->each(static fn (Crawler $node): string => trim($node->text()));
        self::assertSame(['Ausstehend', 'Läuft', 'Abgeschlossen', 'Fehlgeschlagen', 'Abgebrochen', 'Teilweise'], array_values(array_filter($status)));
        self::assertSame(['Zuweisung', 'Schließung', 'Behandlungsdaten'], array_values(array_filter($type)));
    }

    public function testBatchRunIndexShowsTheTranslatedStatusForAGermanAdmin(): void
    {
        $client = self::createClient();
        $run = new ImportBatchRun(['dry_run' => false])->setStatus(ImportBatchRunStatus::Interrupted);
        $this->persistBatchRun($run);
        $client->loginUser(UserFactory::new()->asAdmin()->create(['locale' => 'de']));

        $crawler = $client->request(Request::METHOD_GET, '/admin/import-batch-run');

        self::assertResponseIsSuccessful();
        self::assertSame('Unterbrochen', trim($crawler->filter('tbody tr .badge')->first()->text()));
    }

    public function testBatchRunItemIndexShowsTheTranslatedStatusForAGermanAdmin(): void
    {
        $client = self::createClient();
        $run = new ImportBatchRun(['dry_run' => false]);
        $item = new ImportBatchRunItem(1, 'Import 1')->setStatus(ImportBatchRunItemStatus::DispatchFailed);
        $run->addItem($item);
        $this->persistBatchRun($run, $item);
        $client->loginUser(UserFactory::new()->asAdmin()->create(['locale' => 'de']));

        $crawler = $client->request(Request::METHOD_GET, '/admin/import-batch-run-item');

        self::assertResponseIsSuccessful();
        self::assertSame(['Übergabe fehlgeschlagen'], $crawler->filter('tbody tr .badge')->each(static fn (Crawler $node): string => trim($node->text())));
    }

    private function persistBatchRun(object ...$entities): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();
    }
}
