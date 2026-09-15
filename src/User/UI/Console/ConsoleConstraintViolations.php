<?php

declare(strict_types=1);

namespace App\User\UI\Console;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ConsoleConstraintViolations
{
    public static function firstMessage(ConstraintViolationListInterface $violations): string
    {
        foreach ($violations as $violation) {
            return (string) $violation->getMessage();
        }

        throw new \LogicException('Expected at least one constraint violation.');
    }
}
