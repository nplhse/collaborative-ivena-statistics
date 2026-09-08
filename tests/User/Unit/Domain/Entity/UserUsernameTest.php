<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Entity;

use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserUsernameTest extends TestCase
{
    public function testSetUsernameTrimsLeadingAndTrailingWhitespace(): void
    {
        $user = new User();
        $user->setUsername('  Müller  ');

        self::assertSame('Müller', $user->getUsername());
        self::assertSame('Müller', $user->getUserIdentifier());
    }

    public function testSetUsernameKeepsInternalWhitespaceUntilValidation(): void
    {
        $user = new User();
        $user->setUsername('John Doe');

        self::assertSame('John Doe', $user->getUsername());
    }
}
