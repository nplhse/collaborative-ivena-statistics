<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Mail;

use App\Shared\Infrastructure\Mail\LocalMailCatcher;
use PHPUnit\Framework\TestCase;

final class LocalMailCatcherTest extends TestCase
{
    public function testReceivesOnlyTheLoopbackSmtpDsn(): void
    {
        self::assertTrue(LocalMailCatcher::receives('smtp://127.0.0.1:1025'));
        self::assertFalse(LocalMailCatcher::receives('null://null'));
        self::assertFalse(LocalMailCatcher::receives('smtp://mail.example.org:1025'));
    }

    public function testIsReachableWhileSomethingListensOnLoopback(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);

        try {
            self::assertTrue(new LocalMailCatcher($this->port($server))->isReachable());
        } finally {
            fclose($server);
        }
    }

    public function testIsNotReachableWhenNothingListens(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $port = $this->port($server);
        fclose($server);

        self::assertFalse(new LocalMailCatcher($port)->isReachable());
    }

    /**
     * @param resource $server
     */
    private function port($server): int
    {
        $name = (string) stream_socket_get_name($server, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
