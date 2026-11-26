<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Demo;

use App\Shared\Infrastructure\Demo\DemoMailerDsnEnvVarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

final class DemoMailerDsnEnvVarProcessorTest extends TestCase
{
    public function testMailpitDeliversToTheLocalMailCatcher(): void
    {
        self::assertSame('smtp://127.0.0.1:1025', $this->process('mailpit'));
    }

    public function testOffDiscardsEveryMail(): void
    {
        self::assertSame('null://null', $this->process('off'));
    }

    #[DataProvider('invalidModes')]
    public function testRefusesAnythingElse(mixed $mode): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid value for "DEMO_MAIL": expected one of "mailpit", "off".');

        $this->process($mode);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidModes(): iterable
    {
        yield 'real mail server' => ['smtp://mail.example.org:587'];
        yield 'null transport' => ['null://null'];
        yield 'upper case' => ['OFF'];
        yield 'empty' => [''];
        yield 'not a string' => [null];
    }

    public function testProvidesItsPrefix(): void
    {
        self::assertSame(['demo_mailer_dsn' => 'string'], DemoMailerDsnEnvVarProcessor::getProvidedTypes());
    }

    private function process(mixed $mode): string
    {
        return new DemoMailerDsnEnvVarProcessor()->getEnv(
            DemoMailerDsnEnvVarProcessor::PREFIX,
            'DEMO_MAIL',
            static fn (string $name): mixed => $mode,
        );
    }
}
