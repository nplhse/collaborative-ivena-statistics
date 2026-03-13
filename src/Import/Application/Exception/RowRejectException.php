<?php

namespace App\Import\Application\Exception;

final class RowRejectException extends \RuntimeException
{
    /**
     * @param list<string>        $messages
     * @param array<string,mixed> $context
     */
    public function __construct(
        private readonly array $messages,
        private readonly array $context = [],
    ) {
        parent::__construct($messages[0] ?? 'Row rejected');
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
