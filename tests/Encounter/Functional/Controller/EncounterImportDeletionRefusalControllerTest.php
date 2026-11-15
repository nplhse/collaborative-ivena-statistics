<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Admin\UI\Http\Controller\Import\ImportCrudController;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Application\Message\DeleteImportsMessage;
use App\Import\Application\MessageHandler\DeleteImportsMessageHandler;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\AttachedRecordFixtures;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Translation\TranslatableMessage;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A refused import deletion reaches the user the same way on every path (ADR 037): the import page and the
 * administration show the reason, and a bulk deletion skips the import and deletes the others. The visit a
 * of the `ed_visit` import carries a record of another import, attached before the deletion (guard) or only
 * while it runs (RESTRICT reference).
 */
#[ResetDatabase]
final class EncounterImportDeletionRefusalControllerTest extends WebTestCase
{
    use AttachedRecordFixtures;
    use EncounterDataFixtures;
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private const string REFUSAL = 'This import cannot be deleted or reprocessed while records of other imports are attached to its visits. Delete those imports first.';

    private KernelBrowser $client;

    private Hospital $hospital;

    private User $uploader;

    private int $allocationId;

    private int $otherImportId;

    private int $edImportId;

    private int $visitA;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->hospital = $this->hospital($this->user());
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
        $this->allocationId = (int) $this->allocationAt($this->hospital, $this->encounterImport($this->hospital, 'allocations', ImportType::ALLOCATION), '08:30')->getId();
        $this->otherImportId = (int) $this->encounterImport($this->hospital, 'attaching records', ImportType::ALLOCATION)->getId();
        $this->edImportId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()
            ->visit('a', '2026-10-12T08:15')
            ->build(), 'ed visits');
        $this->visitA = $this->visitIdsOfImport($this->edImportId, 'a')['a'];
        $this->createAttachedRecordTable();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testImportPageShowsTheRefusalAndKeepsTheImport(): void
    {
        $this->attachRecord($this->visitA, $this->otherImportId);

        $this->deleteThroughTheImportPage();

        self::assertResponseRedirects('/import/'.$this->edImportId);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', self::REFUSAL);
        $this->assertImportIsComplete();
    }

    public function testRestrictReferenceShowsTheSameRefusal(): void
    {
        $this->linkVisit($this->allocationId, $this->visitA);
        $this->attachRecordWhenTheLinkIsRemoved($this->otherImportId);

        $this->deleteThroughTheImportPage();

        self::assertResponseRedirects('/import/'.$this->edImportId);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', self::REFUSAL);
        $this->assertImportIsComplete();
        self::assertSame(0, $this->attachedRecordCount(), 'The record attached during the deletion was rolled back.');
    }

    public function testAdministrationShowsTheRefusal(): void
    {
        $this->attachRecord($this->visitA, $this->otherImportId);
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push($request);

        $controller = self::getContainer()->get(ImportCrudController::class);
        self::assertInstanceOf(ImportCrudController::class, $controller);
        $controller->deleteEntity($this->entityManager(), $this->freshImport());

        $flashes = $session->getFlashBag()->get('danger');
        self::assertCount(1, $flashes);
        self::assertInstanceOf(TranslatableMessage::class, $flashes[0]);
        self::assertSame('encounter.import.delete_refused.attached_records', $flashes[0]->getMessage());
        $this->assertImportIsComplete();
    }

    public function testBulkDeletionSkipsTheRefusedImportAndDeletesTheOthers(): void
    {
        $this->attachRecord($this->visitA, $this->otherImportId);
        $deletable = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('b', '2026-10-12T09:15')->build(), 'deletable');
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $uploader = $this->entityManager()->find(User::class, (int) $this->uploader->getId());
        self::assertInstanceOf(User::class, $uploader);
        $tokens->setToken(new UsernamePasswordToken($uploader, 'main', $uploader->getRoles()));

        $handler = self::getContainer()->get(DeleteImportsMessageHandler::class);
        self::assertInstanceOf(DeleteImportsMessageHandler::class, $handler);
        $handler(new DeleteImportsMessage('0199e000-0000-7000-8000-000000001274', (int) $this->uploader->getId(), [$this->edImportId, $deletable]));

        $this->assertImportIsComplete();
        self::assertNull($this->entityManager()->find(Import::class, $deletable));
        $parameters = $this->connection()->fetchOne("SELECT parameters FROM notification WHERE recipient_id = ? AND type = 'import.bulk_deleted'", [$this->uploader->getId()]);
        self::assertSame(['total' => 2, 'deleted' => 1, 'skipped' => 1, 'failed' => 0], json_decode((string) $parameters, true));
    }

    private function deleteThroughTheImportPage(): void
    {
        $this->client->loginUser($this->uploader);
        $crawler = $this->client->request(Request::METHOD_GET, '/import/'.$this->edImportId);
        $token = $crawler->filter('#import-delete-modal input[name="_token"]')->attr('value');
        $this->client->request(Request::METHOD_POST, '/import/'.$this->edImportId.'/delete', ['_token' => $token]);
    }

    private function assertImportIsComplete(): void
    {
        $import = $this->freshImport();
        self::assertSame(1, $this->encounterCount($this->edImportId));
        self::assertFileExists($this->projectDir().'/'.$import->getFilePath());
    }

    private function freshImport(): Import
    {
        $this->entityManager()->clear();
        $import = $this->entityManager()->find(Import::class, $this->edImportId);
        self::assertInstanceOf(Import::class, $import);

        return $import;
    }
}
