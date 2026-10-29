<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Demo;

use App\Shared\Infrastructure\Demo\DemoMailerDsnEnvVarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

final class DemoMailerDsnEnvVarProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null}>
     */
    public static function offValues(): iterable
    {
        yield 'zero' => ['0'];
        yield 'empty' => [''];
        yield 'blank' => [' '];
        yield 'unset' => [null];
    }

    #[DataProvider('offValues')]
    public function testKeepsMailOffByDefault(?string $value): void
    {
        self::assertSame('null://null', $this->resolve($value));
    }

    public function testOptInDeliversToTheLocalMailCatcherOnly(): void
    {
        self::assertSame('smtp://127.0.0.1:1025', $this->resolve('1'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'true' => ['true'];
        yield 'two' => ['2'];
        yield 'a dsn' => ['smtp://mail.example.org:25'];
    }

    #[DataProvider('invalidValues')]
    public function testRefusesAnyOtherValue(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DEMO_MAIL_CATCHER must be "0" (mail off) or "1"');

        $this->resolve($value);
    }

    public function testProvidesItsPrefixAsString(): void
    {
        self::assertSame(['demo_mailer_dsn' => 'string'], DemoMailerDsnEnvVarProcessor::getProvidedTypes());
    }

    private function resolve(?string $value): string
    {
        return new DemoMailerDsnEnvVarProcessor()->getEnv(
            'demo_mailer_dsn',
            'DEMO_MAIL_CATCHER',
            static fn (string $name): ?string => 'DEMO_MAIL_CATCHER' === $name ? $value : null,
        );
    }
}
