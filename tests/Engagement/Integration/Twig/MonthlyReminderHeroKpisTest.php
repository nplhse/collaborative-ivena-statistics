<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Twig\Environment;

final class MonthlyReminderHeroKpisTest extends KernelTestCase
{
    public function testPhysicianBaselineDeltaIsLabelledAsPercentagePoints(): void
    {
        // 25.0 % this month vs. 20.0 % baseline: +5.0 percentage points.
        self::assertStringContainsString('+5.0 pp vs. your last 12 months', $this->render('en', 5.0));
    }

    public function testGermanUnitAndNegativeDelta(): void
    {
        $html = $this->render('de', -2.5);

        self::assertStringContainsString('-2.5 %-Punkte vs. your last 12 months', $html);
        self::assertStringNotContainsString('-2.5%', $html);
    }

    private function render(string $locale, float $deltaPp): string
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);
        $translator->setLocale($locale);

        return self::getContainer()->get(Environment::class)->render('@Engagement/email/_monthly_reminder_hero_kpis.html.twig', [
            'content' => [
                'allocationCount' => 40,
                'allocationMomPercent' => null,
                'lastImportStale' => false,
                'lastImportLabel' => '10.05.2026',
                'withPhysicianPercent' => '25.0',
                'withPhysicianBaselineDeltaPp' => $deltaPp,
                'baselinePeriodLabel' => 'your last 12 months',
                'meanTransportMinutes' => 12.0,
                'meanTransportBaselineDeltaMinutes' => null,
            ],
        ]);
    }
}
