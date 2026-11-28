<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The admin page "Failed messages": list, detail and the delete actions with their CSRF tokens. Which rows the
 * queries read and delete is tested in MessengerStatsRepositoryTest.
 */
#[ResetDatabase]
final class FailedMessengerControllerTest extends WebTestCase
{
    use Factories;

    public function testTheListShowsTheMessageClassAndUsesStimulusInsteadOfInlineScripts(): void
    {
        $client = $this->adminClient();
        $this->insertFailedMessage('test-body-content');

        $client->request(Request::METHOD_GET, '/admin/operations/failed-messages');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('ImportAllocationsMessage', $html);
        self::assertStringContainsString('data-controller="confirm-submit"', $html);
        self::assertStringContainsString('data-controller="checkbox-select-all"', $html);
        self::assertStringNotContainsString('onsubmit=', $html);
        self::assertStringNotContainsString("document.getElementById('failed-messages-select-all')", $html);
    }

    public function testTheDetailShowsHeadersAndBodyOfTheMessage(): void
    {
        $client = $this->adminClient();
        $messageId = $this->insertFailedMessage('test-body-content');

        $client->request(Request::METHOD_GET, '/admin/operations/failed-messages/'.$messageId);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('test-body-content', $html);
        self::assertStringContainsString('data-controller="confirm-submit"', $html);
        self::assertStringNotContainsString('onsubmit=', $html);
        self::assertStringContainsString('ea-failed-messages-pre-headers', $html);
        self::assertStringContainsString('ea-failed-messages-pre-body', $html);
        self::assertCount(1, $client->getCrawler()->filter('link[rel="stylesheet"][href*="admin-kpi"]'), 'admin-kpi.css comes only from the asset-mapper entry (#1236)');
    }

    public function testAdminCanDeleteSingleFailedMessage(): void
    {
        $client = $this->adminClient();
        $messageId = $this->insertFailedMessage('delete-me');

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
        $client = $this->adminClient();
        $firstId = $this->insertFailedMessage('first');
        $secondId = $this->insertFailedMessage('second');
        $thirdId = $this->insertFailedMessage('third');

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/failed-messages');
        $client->request(Request::METHOD_POST, '/admin/operations/failed-messages/delete-selected', [
            '_token' => $this->extractCsrfToken($crawler, 'delete-selected'),
            'ids' => [$firstId, $thirdId],
        ]);

        self::assertResponseRedirects('/admin/operations/failed-messages');
        self::assertNotFalse($this->findFailedMessageById($secondId), 'only the selected messages are deleted');
        self::assertSame(1, $this->countFailedMessages());
    }

    public function testAdminCanDeleteAllFailedMessages(): void
    {
        $client = $this->adminClient();
        $this->insertFailedMessage('one');
        $this->insertFailedMessage('two');

        $crawler = $client->request(Request::METHOD_GET, '/admin/operations/failed-messages');
        $client->request(Request::METHOD_POST, '/admin/operations/failed-messages/delete-all', [
            '_token' => $this->extractCsrfToken($crawler, 'delete-all'),
        ]);

        self::assertResponseRedirects('/admin/operations/failed-messages');
        self::assertSame(0, $this->countFailedMessages());
    }

    public function testDeleteSelectedWithoutSelectionShowsWarning(): void
    {
        $client = $this->adminClient();
        $this->insertFailedMessage('keep-me');

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
        $client->loginUser(UserFactory::createOne());

        $client->request(Request::METHOD_GET, '/admin/operations/failed-messages');

        self::assertResponseStatusCodeSame(403);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        return $client;
    }

    private function insertFailedMessage(string $body): int
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('messenger_messages', [
            'body' => $body,
            'headers' => json_encode(['type' => \App\Import\Application\Message\ImportAllocationsMessage::class], JSON_THROW_ON_ERROR),
            'queue_name' => 'failed',
            'created_at' => '2026-11-13 09:00:00',
            'available_at' => '2026-11-13 09:00:00',
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
