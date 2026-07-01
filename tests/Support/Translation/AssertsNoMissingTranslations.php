<?php

declare(strict_types=1);

namespace App\Tests\Support\Translation;

use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Translation\DataCollector\TranslationDataCollector;
use Symfony\Component\Translation\DataCollectorTranslator;
use Symfony\Component\VarDumper\Cloner\Data;

trait AssertsNoMissingTranslations
{
    protected function assertNoMissingTranslations(?Profile $profile, string $message = 'Missing translations'): void
    {
        self::assertNotNull($profile);

        $collector = $profile->getCollector('translation');
        self::assertInstanceOf(TranslationDataCollector::class, $collector);

        $messages = $collector->getMessages();
        if ($messages instanceof Data) {
            $messages = $messages->getValue(true);
        }

        $missing = array_values(array_filter(
            $messages,
            static fn (array $entry): bool => ($entry['state'] ?? null) === DataCollectorTranslator::MESSAGE_MISSING,
        ));

        self::assertSame([], $missing, $message.': '.json_encode($missing, \JSON_THROW_ON_ERROR));
    }
}
