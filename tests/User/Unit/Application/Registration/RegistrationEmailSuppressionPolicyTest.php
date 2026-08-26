<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Registration;

use App\User\Application\Registration\RegistrationEmailSuppressionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistrationEmailSuppressionPolicyTest extends TestCase
{
    #[DataProvider('tldRuleProvider')]
    public function testMatchesTldSuffixRules(string $email, bool $expected): void
    {
        $policy = new RegistrationEmailSuppressionPolicy(['.ru']);

        self::assertSame($expected, null !== $policy->matchedSuffix($email));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function tldRuleProvider(): iterable
    {
        yield 'example.ru' => ['user@example.ru', true];
        yield 'subdomain of example.ru' => ['user@sub.example.ru', true];
        yield 'uppercase TLD' => ['user@EXAMPLE.RU', true];
        yield 'mixed case local part' => ['User.Name@Example.Ru', true];
        yield 'whitespace around email' => ['  user@example.ru  ', true];
        yield 'trailing dot on domain' => ['user@example.ru.', true];
        yield 'pru.com is not .ru' => ['user@pru.com', false];
        yield 'russia.com is not .ru' => ['user@russia.com', false];
        yield 'example.russia is not .ru' => ['user@example.russia', false];
        yield 'ru as subdomain label' => ['user@ru.example.com', false];
        yield 'allowed example.test' => ['user@example.test', false];
    }

    #[DataProvider('exactDomainRuleProvider')]
    public function testMatchesExactDomainAndSubdomains(string $email, bool $expected): void
    {
        $policy = new RegistrationEmailSuppressionPolicy(['mailinator.com']);

        self::assertSame($expected, null !== $policy->matchedSuffix($email));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function exactDomainRuleProvider(): iterable
    {
        yield 'exact domain' => ['user@mailinator.com', true];
        yield 'subdomain' => ['user@evil.mailinator.com', true];
        yield 'unrelated lookalike' => ['user@notmailinator.com', false];
        yield 'suffix inside different TLD' => ['user@mailinator.com.example.test', false];
    }

    public function testLeadingDotVariantsAreEquivalent(): void
    {
        $withDot = new RegistrationEmailSuppressionPolicy(['.ru']);
        $withoutDot = new RegistrationEmailSuppressionPolicy(['ru']);

        self::assertSame('ru', $withDot->matchedSuffix('user@example.ru'));
        self::assertSame('ru', $withoutDot->matchedSuffix('user@example.ru'));
    }

    public function testEmptyListDoesNotSuppress(): void
    {
        $policy = new RegistrationEmailSuppressionPolicy([]);

        self::assertNull($policy->matchedSuffix('user@example.ru'));
    }

    public function testIgnoresBlankAndDotOnlyRules(): void
    {
        $policy = new RegistrationEmailSuppressionPolicy(['', '   ', '.', '...']);

        self::assertNull($policy->matchedSuffix('user@example.ru'));
    }

    public function testMultipleRulesUseFirstMatch(): void
    {
        $policy = new RegistrationEmailSuppressionPolicy(['mailinator.com', '.ru']);

        self::assertSame('mailinator.com', $policy->matchedSuffix('spam@evil.mailinator.com'));
        self::assertSame('ru', $policy->matchedSuffix('spam@example.ru'));
        self::assertNull($policy->matchedSuffix('user@example.test'));
    }

    public function testMissingAtSignIsNotSuppressed(): void
    {
        $policy = new RegistrationEmailSuppressionPolicy(['.ru']);

        self::assertNull($policy->matchedSuffix('not-an-email'));
        self::assertNull($policy->matchedSuffix('user@'));
        self::assertNull($policy->matchedSuffix('user@.'));
        self::assertNull($policy->matchedSuffix(''));
    }

    public function testNormalizesRuleWhitespaceAndCase(): void
    {
        $policy = new RegistrationEmailSuppressionPolicy(['  .RU  ']);

        self::assertSame('ru', $policy->matchedSuffix('user@example.ru'));
    }
}
