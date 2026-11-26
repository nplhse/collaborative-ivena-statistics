<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

/**
 * Renders the hero KPIs the way the mailer does: inside LocaleSwitcher::runWithLocale() with the
 * recipient's locale, which sets the translator and \Locale::getDefault() for format_number.
 */
final class MonthlyReminderHeroKpisTest extends KernelTestCase
{
    public function testGermanRecipientGetsGermanSeparators(): void
    {
        // 1234 allocations, 25,0 % with physician (+5,0 pp), 12,5 min mean transport (-2,5 min).
        $html = $this->render('de', ['withPhysicianBaselineDeltaPp' => 5.0, 'meanTransportBaselineDeltaMinutes' => -2.5]);

        self::assertStringContainsString('>1.234<', $html);
        self::assertStringContainsString('25,0%', $html);
        self::assertStringContainsString('12,5 min', $html);
        self::assertStringContainsString('+5,0 %-Punkte', $html);
        self::assertStringContainsString('-2,5', $html);
        self::assertStringNotContainsString('12.5', $html);
    }

    public function testEnglishRecipientGetsEnglishSeparators(): void
    {
        $html = $this->render('en', ['withPhysicianBaselineDeltaPp' => 5.0, 'meanTransportBaselineDeltaMinutes' => -2.5]);

        self::assertStringContainsString('>1,234<', $html);
        self::assertStringContainsString('25.0%', $html);
        self::assertStringContainsString('12.5 min', $html);
        self::assertStringContainsString('+5.0 pp vs. your last 12 months', $html);
        self::assertStringContainsString('-2.5', $html);
    }

    public function testNegativePercentagePointDeltaKeepsUnit(): void
    {
        $html = $this->render('de', ['withPhysicianBaselineDeltaPp' => -2.5]);

        self::assertStringContainsString('-2,5 %-Punkte', $html);
        self::assertStringNotContainsString('-2,5%', $html);
    }

    public function testAllocationIncreaseIsGreenWithUpArrow(): void
    {
        // 110 vs. 100 allocations: +10 %.
        $mom = $this->mom($this->render('de', ['allocationMomPercent' => 10.0]));

        self::assertSame('up', $mom->attr('data-mom'));
        self::assertStringContainsString('#2fb344', (string) $mom->attr('style'));
        self::assertSame('▲ 10%', $this->squash($mom->text()));
    }

    public function testAllocationDecreaseIsRedWithDownArrow(): void
    {
        // 90 vs. 100 allocations: -10 %; the arrow carries the sign.
        $mom = $this->mom($this->render('en', ['allocationMomPercent' => -12.5]));

        self::assertSame('down', $mom->attr('data-mom'));
        self::assertStringContainsString('#d63939', (string) $mom->attr('style'));
        self::assertSame('▼ 12.5%', $this->squash($mom->text()));
    }

    public function testNoAllocationChangeIsNeutralWithoutArrow(): void
    {
        // 100 vs. 100, and -0,04 % which rounds to 0: neither is an increase.
        foreach ([0.0, -0.04, 0.04] as $value) {
            $mom = $this->mom($this->render('de', ['allocationMomPercent' => $value]));

            self::assertSame('flat', $mom->attr('data-mom'));
            self::assertStringContainsString('#667382', (string) $mom->attr('style'));
            self::assertSame('0%', $this->squash($mom->text()));
        }
    }

    public function testUndefinedAllocationChangeIsShownAsUnavailable(): void
    {
        // Previous month 0: the change is undefined, not +100 % and not 0 %.
        foreach (['de', 'en'] as $locale) {
            $mom = $this->mom($this->render($locale, ['allocationMomPercent' => null]));

            self::assertSame('unavailable', $mom->attr('data-mom'));
            self::assertStringContainsString('#667382', (string) $mom->attr('style'));
            self::assertSame('—', $this->squash($mom->text()));
        }
    }

    public function testDeltaThatRoundsToZeroHasNoSign(): void
    {
        foreach ([0.04, -0.04, 0.0] as $value) {
            $html = $this->render('de', ['withPhysicianBaselineDeltaPp' => $value, 'meanTransportBaselineDeltaMinutes' => $value]);

            self::assertStringContainsString('0,0 %-Punkte', $html);
            self::assertStringNotContainsString('+0,0', $html);
            self::assertStringNotContainsString('-0,0', $html);
        }
    }

    public function testRoundingStaysHalfUp(): void
    {
        // number_format() rounded 12,25 to 12,3; NumberFormatter's default half-even would give 12,2.
        $html = $this->render('de', ['meanTransportMinutes' => 12.25, 'withPhysicianPercent' => 20.05]);

        self::assertStringContainsString('12,3 min', $html);
        self::assertStringContainsString('20,1%', $html);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function render(string $locale, array $overrides = []): string
    {
        self::bootKernel();
        $container = self::getContainer();
        $switcher = $container->get(LocaleSwitcher::class);
        self::assertInstanceOf(LocaleSwitcher::class, $switcher);
        $twig = $container->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return $switcher->runWithLocale($locale, static fn (): string => $twig->render('@Engagement/email/_monthly_reminder_hero_kpis.html.twig', [
            'content' => array_merge([
                'allocationCount' => 1234,
                'allocationMomPercent' => null,
                'lastImportStale' => false,
                'lastImportLabel' => '10.05.2026',
                'withPhysicianPercent' => 25.0,
                'withPhysicianBaselineDeltaPp' => null,
                'baselinePeriodLabel' => 'your last 12 months',
                'meanTransportMinutes' => 12.5,
                'meanTransportBaselineDeltaMinutes' => null,
            ], $overrides),
        ]));
    }

    private function mom(string $html): Crawler
    {
        $mom = new Crawler($html)->filter('[data-mom]');
        self::assertCount(1, $mom);

        return $mom;
    }

    private function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
