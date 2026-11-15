<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

final class MonthlyReminderClosedDepartmentTest extends KernelTestCase
{
    public function testGermanRecipientGetsGermanSeparators(): void
    {
        // 1234 closed-department allocations, 12,5 % share, +25,0 % against the previous month.
        $html = $this->render('de', 25.0);

        self::assertStringContainsString('1.234', $html);
        self::assertStringContainsString('12,5%', $html);
        self::assertStringContainsString('+25,0%', $html);
    }

    public function testEnglishRecipientGetsEnglishSeparators(): void
    {
        $html = $this->render('en', -25.0);

        self::assertStringContainsString('1,234', $html);
        self::assertStringContainsString('12.5%', $html);
        self::assertStringContainsString('-25.0%', $html);
    }

    public function testChangeThatRoundsToZeroIsNeutralWithoutSign(): void
    {
        foreach ([0.0, 0.04, -0.04] as $value) {
            $html = $this->render('de', $value);

            self::assertStringContainsString('<span style="color:#667382;">0,0%</span>', $html);
            self::assertStringNotContainsString('+0,0', $html);
            self::assertStringNotContainsString('-0,0', $html);
        }
    }

    private function render(string $locale, ?float $momPercent): string
    {
        self::bootKernel();
        $container = self::getContainer();
        $switcher = $container->get(LocaleSwitcher::class);
        self::assertInstanceOf(LocaleSwitcher::class, $switcher);
        $twig = $container->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return $switcher->runWithLocale($locale, static fn (): string => $twig->render('@Engagement/email/_monthly_reminder_closed_department.html.twig', [
            'content' => [
                'closedDepartmentCount' => 1234,
                'closedDepartmentSharePercent' => 12.5,
                'closedDepartmentMomPercent' => $momPercent,
                'monthlyReportUrl' => 'https://example.test/statistics/reports/monthly',
            ],
            'muted' => '#667382',
            'border' => '#e6e7e9',
            'fgHeading' => '#232b42',
            'link' => '#206bc4',
        ]));
    }
}
