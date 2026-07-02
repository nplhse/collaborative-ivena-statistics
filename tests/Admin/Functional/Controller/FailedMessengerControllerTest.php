<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class FailedMessengerControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanInspectFailedMessages(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()->asAdmin()->create([
            'username' => 'failed-msg-admin-'.bin2hex(random_bytes(4)),
        ]);

        $messageId = $this->insertFailedMessage('test-body-content');

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/operations/failed-messages');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ImportAllocationsMessage', $client->getResponse()->getContent());

        $client->request(Request::METHOD_GET, '/admin/operations/failed-messages/'.$messageId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('test-body-content', $client->getResponse()->getContent());
    }

    public function testAdminCanDeleteSingleFailedMessage(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $messageId = $this->insertFailedMessage('delete-me');

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/failed-messages');
        $client->request(Request::METHOD_POST, '/admin/operations/failed-messages/'.$messageId.'/delete', [
            '_token' => $this->extractCsrfToken($crawler, 'failed-messages/'.$messageId.'/delete'),
        ]);

        self::assertResponseRedirects('/admin/operations/failed-messages');
        $client->followRedirect();
        self::assertStringContainsString('Failed message deleted.', $client->getResponse()->getContent());
        self::assertSame(0, $this->countFailedMessages());
    }

    public function testAdminCanDeleteSelectedFailedMessages(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $firstId = $this->insertFailedMessage('first');
        $secondId = $this->insertFailedMessage('second');
        $thirdId = $this->insertFailedMessage('third');

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/failed-messages');
        $client->request(Request::METHOD_POST, '/admin/operations/failed-messages/delete-selected', [
            '_token' => $this->extractCsrfToken($crawler, 'delete-selected'),
            'ids' => [$firstId, $thirdId],
        ]);

        self::assertResponseRedirects('/admin/operations/failed-messages');
        self::assertSame(1, $this->countFailedMessages());
        self::assertNotFalse($this->findFailedMessageById($secondId));
        self::assertFalse($this->findFailedMessageById($firstId));
        self::assertFalse($this->findFailedMessageById($thirdId));
    }

    public function testAdminCanDeleteAllFailedMessages(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $this->insertFailedMessage('one');
        $this->insertFailedMessage('two');

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/failed-messages');
        $client->request(Request::METHOD_POST, '/admin/operations/failed-messages/delete-all', [
            '_token' => $this->extractCsrfToken($crawler, 'delete-all'),
        ]);

        self::assertResponseRedirects('/admin/operations/failed-messages');
        self::assertSame(0, $this->countFailedMessages());
    }

    public function testDeleteSelectedWithoutSelectionShowsWarning(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $this->insertFailedMessage('keep-me');

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/failed-messages');
        $client->request(Request::METHOD_POST, '/admin/operations/failed-messages/delete-selected', [
            '_token' => $this->extractCsrfToken($crawler, 'delete-selected'),
            'ids' => [],
        ]);

        self::assertResponseRedirects('/admin/operations/failed-messages');
        $client->followRedirect();
        self::assertStringContainsString('Select at least one failed message to delete.', $client->getResponse()->getContent());
        self::assertSame(1, $this->countFailedMessages());
    }

    public function testNonAdminIsForbidden(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne();
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/operations/failed-messages');
        self::assertResponseStatusCodeSame(403);
    }

    private function insertFailedMessage(string $body): int
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('messenger_messages', [
            'body' => $body,
            'headers' => json_encode(['type' => \App\Import\Application\Message\ImportAllocationsMessage::class], JSON_THROW_ON_ERROR),
            'queue_name' => 'failed',
            'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            'available_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            'delivered_at' => null,
        ]);

        return (int) $connection->lastInsertId();
    }

    private function countFailedMessages(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => 'failed'],
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    private function findFailedMessageById(int $id): array|false
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection->fetchAssociative(
            'SELECT id FROM messenger_messages WHERE id = :id AND queue_name = :queue',
            ['id' => $id, 'queue' => 'failed'],
        );
    }

    private function extractCsrfToken(Crawler $crawler, string $actionContains): string
    {
        $token = $crawler->filter(sprintf('form[action*="%s"] input[name="_token"]', $actionContains))->first();
        if (0 === $token->count()) {
            self::fail(sprintf('CSRF token for action containing "%s" not found.', $actionContains));
        }

        return (string) $token->attr('value');
    }
}
