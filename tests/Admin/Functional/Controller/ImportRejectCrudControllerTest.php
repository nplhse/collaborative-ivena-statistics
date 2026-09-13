<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportRejectCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanOpenImportRejectDetailWithoutCodeEditorOrInlineScript(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create(['username' => 'reject-admin-'.bin2hex(random_bytes(4))]);

        $import = ImportFactory::createOne([
            'createdBy' => $admin,
            'hospital' => HospitalFactory::createOne(),
        ]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $reject = new ImportReject();
        $reject->setImport($import);
        $reject->setLineNumber(12);
        $reject->setMessages(['createdAt: This value should not be blank.']);
        $reject->setRow(['fachgebiet' => 'Innere Medizin', 'line' => 12]);
        $em->persist($reject);
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/import-reject/'.$reject->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('ea-json-pre', $html);
        self::assertStringContainsString('data-controller="copy-to-clipboard"', $html);
        self::assertStringContainsString('Innere Medizin', $html);
        self::assertStringNotContainsString('data-ea-code-editor-field', $html);
        self::assertStringNotContainsString("document.getElementById('copy-json-btn')", $html);
    }

    public function testAdminCanOpenImportRejectIndexWithoutSearch(): void
    {
        $client = self::createClient();
        $fixture = $this->createSearchFixture();

        $client->loginUser($fixture['admin']);
        $client->request(Request::METHOD_GET, '/admin/import-reject');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($fixture['importName'], $html);
        self::assertStringContainsString($fixture['messageToken'], $html);
    }

    public function testAdminSearchFindsRejectByMessagesJsonText(): void
    {
        $client = self::createClient();
        $fixture = $this->createSearchFixture();

        $client->loginUser($fixture['admin']);
        $client->request(Request::METHOD_GET, '/admin/import-reject', [
            'query' => $fixture['messageToken'],
        ]);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($fixture['importName'], $html);
        self::assertStringContainsString($fixture['messageToken'], $html);
    }

    public function testAdminSearchFindsRejectByImportName(): void
    {
        $client = self::createClient();
        $fixture = $this->createSearchFixture();

        $client->loginUser($fixture['admin']);
        $client->request(Request::METHOD_GET, '/admin/import-reject', [
            'query' => $fixture['importName'],
        ]);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($fixture['importName'], $html);
    }

    public function testAdminSearchWithNoMatchesDoesNotError(): void
    {
        $client = self::createClient();
        $fixture = $this->createSearchFixture();

        $client->loginUser($fixture['admin']);
        $client->request(Request::METHOD_GET, '/admin/import-reject', [
            'query' => 'no-such-reject-token-'.bin2hex(random_bytes(4)),
        ]);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($fixture['importName'], $html);
    }

    /**
     * @return array{admin: User, importName: string, messageToken: string}
     */
    private function createSearchFixture(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $importName = 'reject-import-'.$suffix;
        $messageToken = 'reject-msg-'.$suffix;
        $hospitalName = 'reject-hospital-'.$suffix;

        $admin = UserFactory::new()
            ->asAdmin()
            ->create(['username' => 'reject-search-admin-'.$suffix]);

        $import = ImportFactory::createOne([
            'name' => $importName,
            'createdBy' => $admin,
            'hospital' => HospitalFactory::createOne(['name' => $hospitalName]),
        ]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $reject = new ImportReject();
        $reject->setImport($import);
        $reject->setLineNumber(21);
        $reject->setMessages([$messageToken.': missing field']);
        $reject->setRow(['line' => 21]);
        $em->persist($reject);
        $em->flush();

        return [
            'admin' => $admin,
            'importName' => $importName,
            'messageToken' => $messageToken,
        ];
    }
}
