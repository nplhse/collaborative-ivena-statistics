<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Translation;

use App\Shared\Application\Translation\MissingTranslationReport;
use PHPUnit\Framework\TestCase;

final class MissingTranslationReportTest extends TestCase
{
    private string $translationsDirectory;

    #[\Override]
    protected function setUp(): void
    {
        $this->translationsDirectory = sys_get_temp_dir().'/missing-translation-report-'.uniqid('', true);
        mkdir($this->translationsDirectory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->translationsDirectory);
    }

    public function testReportsMissingKeysByWave(): void
    {
        $this->writeXlf('messages+intl-icu.en.xlf', [
            'label.save' => 'Save',
            'label.cancel' => 'Cancel',
            'stats.overview.title' => 'Overview',
            'public.home.title' => 'Home',
        ]);
        $this->writeXlf('messages+intl-icu.de.xlf', [
            'label.save' => 'Speichern',
        ]);

        $report = new MissingTranslationReport($this->translationsDirectory);
        $sections = $report->build(['messages+intl-icu']);
        $summary = $report->summarize($sections);

        self::assertSame(3, $summary['missing']);
        self::assertSame(1, $summary['existing']);
        self::assertSame(1, $summary['byWave']['shared']['missing']);
        self::assertSame(1, $summary['byWave']['shared']['existing']);
        self::assertSame(1, $summary['byWave']['stats']['missing']);
        self::assertSame(1, $summary['byWave']['content']['missing']);
    }

    public function testDomainWithoutGermanFileIsReportedAsNoDeFile(): void
    {
        $this->writeXlf('validators.en.xlf', [
            'validation.required' => 'This value is required.',
        ]);

        $report = new MissingTranslationReport($this->translationsDirectory);
        $sections = $report->build(['validators'], 'no_de_file');

        self::assertCount(1, $sections);
        self::assertSame('validators', $sections[0]['domain']);
        self::assertSame('no_de_file', $sections[0]['wave']);
        self::assertSame(['validation.required'], $sections[0]['missing']);
    }

    public function testResolveWaveReturnsNullForUnknownPrefix(): void
    {
        $report = new MissingTranslationReport($this->translationsDirectory);

        self::assertSame('shared', $report->resolveWave('label.save'));
        self::assertSame('stats', $report->resolveWave('statistics.distribution.age.18_29'));
        self::assertNull($report->resolveWave('monthly_reminder.subject'));
    }

    /**
     * @param array<string, string> $units
     */
    private function writeXlf(string $filename, array $units): void
    {
        $body = '';
        foreach ($units as $resname => $source) {
            $id = md5($resname);
            $body .= <<<XML
      <trans-unit id="{$id}" resname="{$resname}">
        <source>{$source}</source>
      </trans-unit>

XML;
        }

        file_put_contents($this->translationsDirectory.'/'.$filename, <<<XML
<?xml version="1.0" encoding="utf-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file source-language="en" datatype="plaintext" original="file.ext">
    <body>
{$body}    </body>
  </file>
</xliff>
XML);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if (\in_array($entry, ['.', '..'], true)) {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
