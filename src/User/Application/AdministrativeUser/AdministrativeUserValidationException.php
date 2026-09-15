<?php

declare(strict_types=1);

namespace App\User\Application\AdministrativeUser;

final class AdministrativeUserValidationException extends \RuntimeException
{
    /**
     * @param list<string> $messages
     */
    public function __construct(array $messages)
    {
        parent::__construct(implode("\n", $messages));
    }
}
