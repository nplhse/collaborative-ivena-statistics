<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO\Messenger;

final readonly class MessengerMessageTypeRowDto
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        public string $messageClass,
        public array $transports,
        public MessengerCountsDto $counts,
    ) {
    }

    public function shortName(): string
    {
        $position = strrpos($this->messageClass, '\\');

        return false === $position ? $this->messageClass : substr($this->messageClass, $position + 1);
    }
}
