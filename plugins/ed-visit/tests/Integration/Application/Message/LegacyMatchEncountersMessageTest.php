<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Integration\Application\Message;

use Cois\Plugin\EdVisit\Application\Message\MatchEncountersMessage;
use Cois\Plugin\EdVisit\Infrastructure\Matching\MatchEncountersMessageHandler;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * A MatchEncountersMessage queued before #1300 names the old core class. The worker of the new release still
 * decodes it from the Doctrine transport, the regular queue and the failed one, and the plugin's handler runs it.
 */
final class LegacyMatchEncountersMessageTest extends KernelTestCase
{
    private const string OLD_CLASS = 'App\Encounter\Application\Message\MatchEncountersMessage';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function queues(): iterable
    {
        yield 'regular queue' => ['messenger.transport.async_priority_low', 'low'];
        yield 'failed queue' => ['messenger.transport.failed', 'failed'];
    }

    #[DataProvider('queues')]
    public function testAMessageWithTheOldClassNameReachesThePluginHandler(string $transportId, string $queue): void
    {
        self::bootKernel();
        $this->storeWithOldClassName($queue);

        $transport = self::getContainer()->get($transportId);
        self::assertInstanceOf(TransportInterface::class, $transport);
        $envelopes = [...$transport->get()];
        self::assertCount(1, $envelopes);
        $message = $envelopes[0]->getMessage();
        self::assertInstanceOf(MatchEncountersMessage::class, $message);
        self::assertSame(42, $message->hospitalId);

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $handled = $bus->dispatch($envelopes[0]->with(new ReceivedStamp('async_priority_low')))->all(HandledStamp::class);
        self::assertCount(1, $handled);
        self::assertStringStartsWith(MatchEncountersMessageHandler::class, $handled[0]->getHandlerName());
        $transport->ack($envelopes[0]);
    }

    private function storeWithOldClassName(string $queue): void
    {
        // An empty day list keeps the handler from running the matcher; the message only has to reach it.
        $encoded = new PhpSerializer()->encode(new Envelope(new MatchEncountersMessage(42, [])));
        $serialized = stripslashes($encoded['body']);
        $new = MatchEncountersMessage::class;
        $old = str_replace(sprintf('O:%d:"%s"', \strlen($new), $new), sprintf('O:%d:"%s"', \strlen(self::OLD_CLASS), self::OLD_CLASS), $serialized);
        self::assertNotSame($serialized, $old);

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queue]);
        $connection->insert('messenger_messages', [
            'body' => addslashes($old),
            'headers' => json_encode($encoded['headers'] ?? [], \JSON_THROW_ON_ERROR),
            'queue_name' => $queue,
            'created_at' => '2026-11-17 00:00:00',
            'available_at' => '2026-11-17 00:00:00',
        ]);
    }
}
