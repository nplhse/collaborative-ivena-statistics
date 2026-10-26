<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO\Messenger;

/**
 * Failed attempts of one message type with one exception class (messenger_failure, ADR 019).
 */
final readonly class MessengerFailureRowDto
{
    public function __construct(
        public string $messageClass,
        public string $errorClass,
        public int $attempts,
        public int $retried,
        public int $final,
        public \DateTimeImmutable $lastOccurredAt,
    ) {
    }

    public function messageShortName(): string
    {
        return $this->shortName($this->messageClass);
    }

    public function errorShortName(): string
    {
        return $this->shortName($this->errorClass);
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }
}
