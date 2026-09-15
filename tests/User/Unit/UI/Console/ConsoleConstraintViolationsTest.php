<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Console;

use App\User\UI\Console\ConsoleConstraintViolations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationList;

final class ConsoleConstraintViolationsTest extends TestCase
{
    public function testFirstMessageThrowsWhenThereAreNoViolations(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Expected at least one constraint violation.');

        ConsoleConstraintViolations::firstMessage(new ConstraintViolationList());
    }
}
