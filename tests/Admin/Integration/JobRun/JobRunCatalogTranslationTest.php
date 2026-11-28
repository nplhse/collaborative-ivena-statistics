<?php

declare(strict_types=1);

namespace App\Tests\Admin\Integration\JobRun;

use App\Admin\Application\Service\JobRun\JobRunCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * The job run list labels jobs, statuses, triggers, thresholds and metrics with `admin.job_run.<kind>.<value>`.
 * Each value of JobRunCatalog needs an EN and a DE message, otherwise the list shows the raw key.
 */
final class JobRunCatalogTranslationTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function labels(): iterable
    {
        $kinds = [
            'job' => JobRunCatalog::JOBS,
            'status' => array_keys(JobRunCatalog::STATUSES),
            'trigger' => JobRunCatalog::triggers(),
            'threshold' => JobRunCatalog::THRESHOLDS,
            'metric' => JobRunCatalog::METRIC_LABELS,
        ];
        foreach (['en', 'de'] as $locale) {
            foreach ($kinds as $kind => $values) {
                yield $locale.' '.$kind => [$locale, $kind, $values];
            }
        }
    }

    /**
     * @param list<string> $values
     */
    #[DataProvider('labels')]
    public function testEveryValueHasALabel(string $locale, string $kind, array $values): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue($locale);

        // defines() ignores the fallback locale, so a missing DE message is not hidden by the EN one.
        $missing = array_values(array_filter(
            $values,
            static fn (string $value): bool => !$catalogue->defines('admin.job_run.'.$kind.'.'.$value, 'admin+intl-icu'),
        ));

        self::assertSame([], $missing);
    }
}
