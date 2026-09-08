<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Http\DTO;

use App\User\UI\Http\DTO\LoginTypeDTO;
use PHPUnit\Framework\TestCase;

final class LoginTypeDTOTest extends TestCase
{
    public function testSetUsernameTrimsWhitespace(): void
    {
        $dto = new LoginTypeDTO();
        $dto->setUsername('  alice  ');

        self::assertSame('alice', $dto->getUsername());
    }

    public function testGetUsernameTrimsPublicPropertyAssignment(): void
    {
        $dto = new LoginTypeDTO();
        $dto->username = "\u{00A0}bob\u{00A0}";

        self::assertSame('bob', $dto->getUsername());
    }
}
