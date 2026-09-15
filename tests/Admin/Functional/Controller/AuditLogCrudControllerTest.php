<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuditLogCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanOpenAuditLogIndex(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'audit-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/audit-log');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Audit log');
    }

    public function testNonAdminUserGetsForbiddenOnAuditLogIndex(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne([
            'username' => 'audit-regular-'.bin2hex(random_bytes(4)),
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/audit-log');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuditLogDetailUsesCssClassesInsteadOfInlineStyles(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'audit-detail-'.bin2hex(random_bytes(4)),
            ])
        ;

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $entry = new AuditEntry(
            new \DateTimeImmutable('2026-09-13 12:00:00'),
            'audit-csp-'.bin2hex(random_bytes(4)),
            $admin,
            'http',
            'update',
            User::class,
            (string) $admin->getId(),
            [
                'username' => ['old' => 'before', 'new' => 'after'],
                'roles' => ['old' => ['ROLE_USER'], 'new' => ['ROLE_ADMIN']],
            ],
            ['intent' => 'user.update'],
        );
        $em->persist($entry);
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/audit-log/'.$entry->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('ea-audit-diff-label', $html);
        self::assertStringContainsString('ea-audit-json-panel', $html);
        self::assertStringNotContainsString('style="letter-spacing', $html);
        self::assertStringNotContainsString('style="max-height', $html);
        self::assertStringContainsString('user.update', $html);
        self::assertStringContainsString($entry->getRequestId(), $html);
        self::assertStringContainsString('Request ID', $html);
    }

    public function testAuditLogIndexOmitsSecondaryColumns(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'audit-index-'.bin2hex(random_bytes(4)),
            ])
        ;

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $entry = new AuditEntry(
            new \DateTimeImmutable('2026-09-15 12:00:00'),
            'audit-index-'.bin2hex(random_bytes(4)),
            $admin,
            'http',
            'update',
            User::class,
            (string) $admin->getId(),
            [
                'username' => ['old' => 'before', 'new' => 'after'],
            ],
            ['intent' => 'user.update'],
        );
        $em->persist($entry);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/audit-log');
        self::assertResponseIsSuccessful();

        $headerText = implode(' | ', $crawler->filter('table thead th')->each(static fn ($node): string => trim($node->text())));
        self::assertStringContainsString('Time', $headerText);
        self::assertStringContainsString('Intent', $headerText);
        self::assertStringContainsString('Action', $headerText);
        self::assertStringContainsString('Entity', $headerText);
        self::assertStringContainsString('Changed fields', $headerText);
        self::assertStringContainsString('Actor', $headerText);
        self::assertStringNotContainsString('Origin', $headerText);
        self::assertStringNotContainsString('Request ID', $headerText);
        self::assertStringNotContainsString('Entity ID', $headerText);
    }
}
