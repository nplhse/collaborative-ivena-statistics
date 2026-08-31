<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\DateTime;

use App\Shared\Application\DateTime\RelativeTimestampFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RelativeTimestampFormatterTest extends TestCase
{
    private const string NOW = '2026-09-01 12:00:00';

    /**
     * @param array<string, int> $expectedParameters
     */
    #[DataProvider('bucketProvider')]
    public function testSelectsExpectedTranslationBucket(
        string $occurredAt,
        string $expectedId,
        array $expectedParameters = [],
    ): void {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');
        $translator->expects(self::once())
            ->method('trans')
            ->with($expectedId, $expectedParameters, 'shared')
            ->willReturn($expectedId);

        $formatted = $this->formatter($translator)->format(
            new \DateTimeImmutable($occurredAt, new \DateTimeZone('Europe/Berlin')),
        );

        self::assertSame($expectedId, $formatted->relativeLabel);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: array<string, int>}>
     */
    public static function bucketProvider(): iterable
    {
        yield 'same instant' => [self::NOW, 'time.relative.just_now'];
        yield 'future' => ['2026-09-01 12:00:10', 'time.relative.just_now'];
        yield '44 seconds' => ['2026-09-01 11:59:16', 'time.relative.just_now'];
        yield '45 seconds' => ['2026-09-01 11:59:15', 'time.relative.minute', ['count' => 1]];
        yield '5 minutes' => ['2026-09-01 11:55:00', 'time.relative.minute', ['count' => 5]];
        yield '59 minutes' => ['2026-09-01 11:01:00', 'time.relative.minute', ['count' => 59]];
        yield '1 hour same day' => ['2026-09-01 11:00:00', 'time.relative.hour', ['count' => 1]];
        yield '5 hours same day' => ['2026-09-01 07:00:00', 'time.relative.hour', ['count' => 5]];
        yield 'yesterday same clock' => ['2026-08-31 12:00:00', 'time.relative.yesterday'];
        yield 'yesterday evening' => ['2026-08-31 18:00:00', 'time.relative.yesterday'];
        yield '2 days' => ['2026-08-30 12:00:00', 'time.relative.day', ['count' => 2]];
        yield '6 days' => ['2026-08-26 12:00:00', 'time.relative.day', ['count' => 6]];
        yield '1 week' => ['2026-08-25 12:00:00', 'time.relative.week', ['count' => 1]];
        yield '2 weeks' => ['2026-08-18 12:00:00', 'time.relative.week', ['count' => 2]];
        yield '4 weeks' => ['2026-07-29 12:00:00', 'time.relative.week', ['count' => 4]];
        yield 'about 1 month' => ['2026-07-23 12:00:00', 'time.relative.month', ['count' => 1]];
        yield '6 months' => ['2026-03-01 12:00:00', 'time.relative.month', ['count' => 6]];
        yield '1 year' => ['2025-09-01 12:00:00', 'time.relative.year', ['count' => 1]];
        yield '2 years' => ['2024-09-01 12:00:00', 'time.relative.year', ['count' => 2]];
    }

    public function testPreviousCalendarDayWithinOneHourIsYesterday(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');
        $translator->expects(self::once())
            ->method('trans')
            ->with('time.relative.yesterday', [], 'shared')
            ->willReturn('yesterday');

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-09-01 00:30:00', new \DateTimeZone('Europe/Berlin')));

        $formatted = new RelativeTimestampFormatter($translator, $clock)->format(
            new \DateTimeImmutable('2026-08-31 23:30:00', new \DateTimeZone('Europe/Berlin')),
        );

        self::assertSame('yesterday', $formatted->relativeLabel);
    }

    public function testConvertsUtcInstantIntoBerlinRelativeBucket(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');
        $translator->expects(self::once())
            ->method('trans')
            ->with('time.relative.minute', ['count' => 5], 'shared')
            ->willReturn('5 minutes ago');

        $formatted = $this->formatter($translator)->format(
            new \DateTimeImmutable('2026-09-01 09:55:00', new \DateTimeZone('UTC')),
        );

        self::assertSame('5 minutes ago', $formatted->relativeLabel);
        self::assertSame('2026-09-01T11:55:00+02:00', $formatted->iso8601);
    }

    public function testFormatsAbsoluteEnglishLabel(): void
    {
        $formatted = $this->formatter($this->icuTranslator('en'))->format(
            new \DateTimeImmutable('2026-08-27 14:32:00', new \DateTimeZone('Europe/Berlin')),
        );

        self::assertSame('27 August 2026, 14:32', $formatted->absoluteLabel);
    }

    public function testFormatsAbsoluteGermanLabel(): void
    {
        $formatted = $this->formatter($this->icuTranslator('de'))->format(
            new \DateTimeImmutable('2026-08-27 14:32:00', new \DateTimeZone('Europe/Berlin')),
        );

        self::assertSame('27. August 2026, 14:32', $formatted->absoluteLabel);
    }

    public function testRendersLocalizedRelativeWording(): void
    {
        $english = $this->formatter($this->icuTranslator('en'))->format(
            new \DateTimeImmutable('2026-09-01 11:55:00', new \DateTimeZone('Europe/Berlin')),
        );
        $german = $this->formatter($this->icuTranslator('de'))->format(
            new \DateTimeImmutable('2026-09-01 11:55:00', new \DateTimeZone('Europe/Berlin')),
        );

        self::assertSame('5 minutes ago', $english->relativeLabel);
        self::assertSame('vor 5 Minuten', $german->relativeLabel);
    }

    public function testRendersSingularEnglishPhrases(): void
    {
        $formatted = $this->formatter($this->icuTranslator('en'))->format(
            new \DateTimeImmutable('2026-09-01 11:00:00', new \DateTimeZone('Europe/Berlin')),
        );

        self::assertSame('an hour ago', $formatted->relativeLabel);
    }

    private function formatter(TranslatorInterface $translator): RelativeTimestampFormatter
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Berlin')));

        return new RelativeTimestampFormatter($translator, $clock);
    }

    private function icuTranslator(string $locale): TranslatorInterface
    {
        $translator = new Translator($locale);
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'time.relative.just_now' => 'en' === $locale ? 'just now' : 'gerade eben',
            'time.relative.minute' => 'en' === $locale
                ? '{count, plural, =1 {a minute ago} other {# minutes ago}}'
                : '{count, plural, =1 {vor einer Minute} other {vor # Minuten}}',
            'time.relative.hour' => 'en' === $locale
                ? '{count, plural, =1 {an hour ago} other {# hours ago}}'
                : '{count, plural, =1 {vor einer Stunde} other {vor # Stunden}}',
            'time.relative.yesterday' => 'en' === $locale ? 'yesterday' : 'gestern',
            'time.relative.day' => 'en' === $locale
                ? '{count, plural, =1 {a day ago} other {# days ago}}'
                : '{count, plural, =1 {vor einem Tag} other {vor # Tagen}}',
            'time.relative.week' => 'en' === $locale
                ? '{count, plural, =1 {a week ago} other {# weeks ago}}'
                : '{count, plural, =1 {vor einer Woche} other {vor # Wochen}}',
            'time.relative.month' => 'en' === $locale
                ? '{count, plural, =1 {a month ago} other {# months ago}}'
                : '{count, plural, =1 {vor einem Monat} other {vor # Monaten}}',
            'time.relative.year' => 'en' === $locale
                ? '{count, plural, =1 {a year ago} other {# years ago}}'
                : '{count, plural, =1 {vor einem Jahr} other {vor # Jahren}}',
        ], $locale, 'shared+intl-icu');

        return $translator;
    }
}
