<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Translation;

use App\Allocation\Application\DTO\MyHospitals\DataStatusFindingType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every hint type of My Hospitals (#971) has an ICU message in English and German that formats with the parameters
 * of `_my_hospitals_finding.html.twig`.
 */
final class MyHospitalsFindingTranslationTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function messages(): iterable
    {
        yield 'en possible gap, one month' => ['en', 'possible_gap', 1, 'No data for March 2026 although imports cover the time before and after. An import may be missing.'];
        yield 'en possible gap, months' => ['en', 'possible_gap', 2, 'No data from March 2026 to April 2026 although imports cover the time before and after. An import may be missing.'];
        yield 'de possible gap, months' => ['de', 'possible_gap', 2, 'Von March 2026 bis April 2026 liegen keine Daten vor, obwohl Importe die Zeit davor und danach abdecken. Vielleicht fehlt ein Import.'];
        yield 'en volume change' => ['en', 'volume_change', 60, 'March 2026: 60 % fewer allocations than in the months before.'];
        yield 'de volume change' => ['de', 'volume_change', 60, 'March 2026: 60 % weniger Zuweisungen als in den Monaten davor.'];
        yield 'en not updated' => ['en', 'not_updated', 91, 'The latest allocation is from 1 Mar 2026, 91 days ago.'];
        yield 'de rejects, one row' => ['de', 'import_rejects', 1, 'Aus dem Import vom 1 Mar 2026 konnte 1 Zeile nicht übernommen werden.'];
        yield 'en day gaps' => ['en', 'import_day_gaps', 3, 'The import of 1 Mar 2026 has 3 days without records.'];
    }

    #[DataProvider('messages')]
    public function testMessageFormats(string $locale, string $type, int $amount, string $expected): void
    {
        self::assertSame($expected, $this->translate($locale, $type, $amount));
    }

    public function testEveryTypeHasAnEnglishAndGermanMessage(): void
    {
        foreach (['en', 'de'] as $locale) {
            foreach (DataStatusFindingType::cases() as $type) {
                $key = 'my_hospitals.finding.'.$type->value;
                self::assertNotSame($key, $this->translate($locale, $type->value, 2), \sprintf('%s is missing in %s.', $key, $locale));
            }
        }
    }

    private function translate(string $locale, string $type, int $amount): string
    {
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        // The template passes dates already formatted; plain strings stand in for them here.
        return $translator->trans('my_hospitals.finding.'.$type, [
            'amount' => $amount,
            'date' => '1 Mar 2026',
            'month' => 'March 2026',
            'end' => 'April 2026',
            'direction' => 'down',
        ], 'allocation', $locale);
    }
}
