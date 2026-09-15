<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Admin\UI\Http\Controller\Import\ImportCrudController;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
    }
}
