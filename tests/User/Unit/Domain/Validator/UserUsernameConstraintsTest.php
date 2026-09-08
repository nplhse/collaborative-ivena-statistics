<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Validator;

use App\User\Domain\Validator\UserUsernameConstraints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class UserUsernameConstraintsTest extends TestCase
{
    public function testForUsernameUsesPolicyConstants(): void
    {
        $constraints = UserUsernameConstraints::forUsername();

        self::assertCount(3, $constraints);
        self::assertInstanceOf(NotBlank::class, $constraints[0]);
        self::assertInstanceOf(Length::class, $constraints[1]);
        self::assertSame(UserUsernameConstraints::MIN_LENGTH, $constraints[1]->min);
        self::assertSame(UserUsernameConstraints::MAX_LENGTH, $constraints[1]->max);
        self::assertInstanceOf(Regex::class, $constraints[2]);
        self::assertSame(UserUsernameConstraints::PATTERN, $constraints[2]->pattern);
        self::assertSame('validation.username.format', $constraints[2]->message);
    }

    #[DataProvider('validUsernameProvider')]
    public function testIsValidAcceptsCanonicalUsernames(string $username): void
    {
        self::assertTrue(UserUsernameConstraints::isValid($username));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validUsernameProvider(): iterable
    {
        yield 'ascii letters' => ['Alice'];
        yield 'digits and hyphen' => ['user-12'];
        yield 'dot and underscore' => ['user.name_1'];
        yield 'umlauts' => ['Müller'];
        yield 'eszett' => ['groß-1'];
        yield 'minimum length' => ['abc'];
    }

    #[DataProvider('invalidUsernameProvider')]
    public function testIsValidRejectsForbiddenUsernames(string $username): void
    {
        self::assertFalse(UserUsernameConstraints::isValid($username));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUsernameProvider(): iterable
    {
        yield 'internal space' => ['John Doe'];
        yield 'nbsp' => ["John\u{00A0}Doe"];
        yield 'at sign' => ['user@host'];
        yield 'exclamation' => ['foo!'];
        yield 'too short' => ['ab'];
        yield 'empty' => [''];
        yield 'emoji' => ['user😀'];
    }

    public function testTrimRemovesUnicodeWhitespaceAtEdges(): void
    {
        self::assertSame('Müller', UserUsernameConstraints::trim('  Müller  '));
        self::assertSame('user', UserUsernameConstraints::trim("\u{00A0}user\u{00A0}"));
        self::assertSame('John Doe', UserUsernameConstraints::trim(' John Doe '));
    }

    public function testProposeNormalizedReplacesInternalWhitespaceWithHyphen(): void
    {
        self::assertSame('John-Doe', UserUsernameConstraints::proposeNormalized(' John  Doe '));
        self::assertSame('foo@bar', UserUsernameConstraints::proposeNormalized('foo@bar'));
        self::assertSame('Müller', UserUsernameConstraints::proposeNormalized("\u{00A0}Müller\u{00A0}"));
    }
}
