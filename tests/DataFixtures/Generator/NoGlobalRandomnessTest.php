<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use PHPUnit\Framework\TestCase;

/**
 * The generator draws only from seeded streams (ADR 022). Global random functions would make the
 * data depend on what else was generated before, or on nothing at all.
 */
final class NoGlobalRandomnessTest extends TestCase
{
    private const array DIRECTORIES = ['Allocation', 'Generator', 'Pattern'];

    private const string FORBIDDEN = '/(?<![\w>:$])(mt_srand|mt_rand|mt_getrandmax|random_int|rand|srand|shuffle|str_shuffle|array_rand|lcg_value)\s*\(/';

    public function testTheGeneratorUsesNoGlobalRandomFunctions(): void
    {
        $root = \dirname(__DIR__, 3).'/src/DataFixtures/';
        $found = [];

        foreach (self::DIRECTORIES as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }
                foreach (file($file->getPathname()) ?: [] as $number => $line) {
                    if (1 === preg_match(self::FORBIDDEN, $line, $match)) {
                        $found[] = sprintf('%s:%d %s()', substr($file->getPathname(), \strlen($root)), $number + 1, $match[1]);
                    }
                }
            }
        }

        self::assertSame([], $found, 'Use a stream from RandomStreams instead.');
    }

    public function testThePatternFindsTheForbiddenCalls(): void
    {
        self::assertSame(1, preg_match(self::FORBIDDEN, '$x = mt_rand(0, 3);'));
        self::assertSame(1, preg_match(self::FORBIDDEN, 'return random_int(1, 2);'));
        self::assertSame(0, preg_match(self::FORBIDDEN, '$random->getInt(0, 3);'));
        self::assertSame(0, preg_match(self::FORBIDDEN, 'private function shuffleRows(): void'));
    }
}
