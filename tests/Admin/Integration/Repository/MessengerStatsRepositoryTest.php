<?php

declare(strict_types=1);

namespace App\Tests\Admin\Integration\Repository;

use App\Admin\Infrastructure\Repository\MessengerStatsRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The failure queue on the admin page "Failed messages"; the page itself is tested in FailedMessengerControllerTest.
 */
#[ResetDatabase]
final class MessengerStatsRepositoryTest extends KernelTestCase
{
    use Factories;

    private const string IMPORT = \App\Import\Application\Message\ImportAllocationsMessage::class;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testFailedMessagesAreListedNewestFirstWithTheirMessageClass(): void
    {
        $older = $this->insert('failed', 'older', '2026-11-13 09:00:00');
        $newer = $this->insert('failed', 'newer', '2026-11-13 10:00:00');
        $this->insert('low', 'not failed', '2026-11-13 11:00:00');

        $messages = $this->repository()->findFailedMessages();

        self::assertSame([$newer, $older], array_column($messages, 'id'));
        self::assertSame([self::IMPORT, self::IMPORT], array_column($messages, 'message_class'));
    }

    public function testTheErrorPreviewCollapsesWhitespaceAndIsCutAfter157Characters(): void
    {
        $this->insert('failed', "first\n\n  line ".str_repeat('x', 200), '2026-11-13 09:00:00');

        $preview = $this->repository()->findFailedMessages()[0]['error_preview'];

        self::assertSame(160, \strlen($preview));
        self::assertStringStartsWith('first line xxx', $preview);
        self::assertStringEndsWith('x...', $preview);
    }

    public function testAMessageWithoutTypeHeaderHasNoClass(): void
    {
        $this->insert('failed', 'body', '2026-11-13 09:00:00', headers: '{}');

        $messages = $this->repository()->findFailedMessages();

        self::assertSame('', $messages[0]['message_class']);
    }

    public function testOnlyTheFailureQueueIsCounted(): void
    {
        $this->insert('failed', 'failed', '2026-11-13 09:00:00');
        $this->insert('low', 'ready', '2026-11-13 09:00:00');

        $count = $this->repository()->countFailedMessages();

        self::assertSame(1, $count);
    }

    public function testAMessageOfAnotherQueueIsNotFoundById(): void
    {
        $ready = $this->insert('low', 'ready', '2026-11-13 09:00:00');

        $message = $this->repository()->findFailedMessageById($ready);

        self::assertNull($message);
    }

    public function testDeletingByIdLeavesOtherQueuesAlone(): void
    {
        $ready = $this->insert('low', 'ready', '2026-11-13 09:00:00');

        $deleted = $this->repository()->deleteFailedMessageById($ready);

        self::assertFalse($deleted);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testDeletingSelectedIdsLeavesOtherQueuesAlone(): void
    {
        $failed = $this->insert('failed', 'failed', '2026-11-13 09:00:00');
        $ready = $this->insert('low', 'ready', '2026-11-13 09:00:00');

        $deleted = $this->repository()->deleteFailedMessagesByIds([$failed, $ready]);

        self::assertSame(1, $deleted);
        self::assertSame([$ready], array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT id FROM messenger_messages')));
    }

    public function testDeleteAllEmptiesTheFailureQueueOnly(): void
    {
        $this->insert('failed', 'one', '2026-11-13 09:00:00');
        $this->insert('failed', 'two', '2026-11-13 09:00:00');
        $this->insert('mail', 'mail', '2026-11-13 09:00:00');

        $deleted = $this->repository()->deleteAllFailedMessages();

        self::assertSame(2, $deleted);
        self::assertSame(['mail'], $this->connection()->fetchFirstColumn('SELECT queue_name FROM messenger_messages'));
    }

    private function repository(): MessengerStatsRepository
    {
        return self::getContainer()->get(MessengerStatsRepository::class);
    }

    private function insert(string $queue, string $body, string $createdAt, ?string $headers = null): int
    {
        $this->connection()->insert('messenger_messages', [
            'body' => $body,
            'headers' => $headers ?? json_encode(['type' => self::IMPORT], \JSON_THROW_ON_ERROR),
            'queue_name' => $queue,
            'created_at' => $createdAt,
            'available_at' => $createdAt,
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
