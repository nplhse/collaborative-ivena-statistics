<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Entity;

use App\User\Domain\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserDisplayNameTest extends TestCase
{
    public function testDisplayNameIsEmptyByDefault(): void
    {
        self::assertNull(new User()->getDisplayName());
    }

    #[DataProvider('displayNames')]
    public function testSetDisplayNameNormalisesWhitespace(?string $input, ?string $expected): void
    {
        $user = new User();
        $user->setDisplayName($input);

        self::assertSame($expected, $user->getDisplayName());
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function displayNames(): iterable
    {
        yield 'kept as is' => ['Michael Müller', 'Michael Müller'];
        yield 'trimmed' => ['  Ümit Özdemir  ', 'Ümit Özdemir'];
        yield 'inner whitespace collapsed' => ["Hans-Peter \t  Müller", 'Hans-Peter Müller'];
        yield 'empty becomes null' => ['', null];
        yield 'whitespace only becomes null' => ['   ', null];
        yield 'null stays null' => [null, null];
    }
}
