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

        self::assertCount(4, $constraints);
        self::assertInstanceOf(NotBlank::class, $constraints[0]);
        self::assertInstanceOf(Length::class, $constraints[1]);
        self::assertSame(UserUsernameConstraints::MIN_LENGTH, $constraints[1]->min);
        self::assertSame(UserUsernameConstraints::MAX_LENGTH, $constraints[1]->max);
        self::assertInstanceOf(Regex::class, $constraints[2]);
        self::assertSame(UserUsernameConstraints::PATTERN, $constraints[2]->pattern);
        self::assertSame('validation.username.format', $constraints[2]->message);
        self::assertInstanceOf(Regex::class, $constraints[3]);
        self::assertSame(UserUsernameConstraints::DIGITS_ONLY_PATTERN, $constraints[3]->pattern);
        self::assertFalse($constraints[3]->match);
        self::assertSame('validation.username.digits_only', $constraints[3]->message);
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
        yield 'digits with one letter' => ['1234a'];
        yield 'digits with a dot' => ['12.34'];
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
        yield 'digits only, reserved for IDs' => ['1234'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function trimCases(): iterable
    {
        yield 'spaces at the edges' => ['  Müller  ', 'Müller'];
        yield 'non-breaking spaces at the edges' => ["\u{00A0}user\u{00A0}", 'user'];
        yield 'inner space stays' => [' John Doe ', 'John Doe'];
    }

    #[DataProvider('trimCases')]
    public function testTrimRemovesUnicodeWhitespaceAtEdges(string $input, string $expected): void
    {
        $trimmed = UserUsernameConstraints::trim($input);

        self::assertSame($expected, $trimmed);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function proposeNormalizedCases(): iterable
    {
        yield 'inner whitespace becomes one hyphen' => [' John  Doe ', 'John-Doe'];
        // Only whitespace is replaced; other invalid characters are left for the validator to report.
        yield 'other characters stay' => ['foo@bar', 'foo@bar'];
        yield 'non-breaking spaces at the edges' => ["\u{00A0}Müller\u{00A0}", 'Müller'];
    }

    #[DataProvider('proposeNormalizedCases')]
    public function testProposeNormalizedReplacesInternalWhitespaceWithHyphen(string $input, string $expected): void
    {
        $proposal = UserUsernameConstraints::proposeNormalized($input);

        self::assertSame($expected, $proposal);
    }
}
