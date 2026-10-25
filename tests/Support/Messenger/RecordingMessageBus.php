<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Collects dispatched messages instead of handling them.
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    #[\Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message instanceof Envelope ? $message->getMessage() : $message;

        return Envelope::wrap($message, $stamps);
    }
}
