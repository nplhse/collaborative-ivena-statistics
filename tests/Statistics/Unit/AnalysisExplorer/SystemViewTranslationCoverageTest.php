<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerSystemViewSeeder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SystemViewTranslationCoverageTest extends KernelTestCase
{
    public function testAllSystemViewTranslationKeysExistInEnglishAndGerman(): void
    {
        self::bootKernel();

        $seeder = self::getContainer()->get(ExplorerSystemViewSeeder::class);
        self::assertInstanceOf(ExplorerSystemViewSeeder::class, $seeder);

        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        foreach ($seeder->definitions() as $definition) {
            $slug = $definition['slug'];
            $titleKey = ExplorerSystemViewSeeder::titleKey($slug);
            $descriptionKey = ExplorerSystemViewSeeder::descriptionKey($slug);

            foreach (['en', 'de'] as $locale) {
                $title = $translator->trans($titleKey, [], 'statistics', $locale);
                self::assertNotSame($titleKey, $title, sprintf('Missing %s title translation for %s', $locale, $slug));

                $description = $translator->trans($descriptionKey, [], 'statistics', $locale);
                self::assertNotSame($descriptionKey, $description, sprintf('Missing %s description translation for %s', $locale, $slug));
            }
        }
    }
}
