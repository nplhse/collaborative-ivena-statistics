<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Infrastructure\Factory\ImportFactory;
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
}
