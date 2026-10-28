<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\UI;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every icon name in the code must exist as an SVG in assets/icons/.
 *
 * `bin/console ux:icons:lock` only finds names written in templates. Many names come from PHP (presenters, enums,
 * view models) and reach `ux_icon()` as variables, so this test scans the source for them. A missing file would
 * otherwise be fetched from api.iconify.design at render time (disabled in the test env).
 *
 * Fix a failure with `bin/console ux:icons:import <prefix>:<name>` and commit the SVG.
 */
final class IconFilesTest extends TestCase
{
    private const array SCANNED_DIRS = ['src', 'templates', 'config', 'assets'];

    public function testEveryUsedIconExistsLocally(): void
    {
        $root = \dirname(__DIR__, 4);
        $iconDir = $root.'/assets/icons';
        $prefixes = $this->iconSetPrefixes($iconDir);
        self::assertNotEmpty($prefixes);

        $missing = [];
        foreach ($this->usedIconNames($root, $prefixes) as $name => $file) {
            // "tabler:user-plus" lives in assets/icons/tabler/user-plus.svg
            if (!is_file(\sprintf('%s/%s.svg', $iconDir, str_replace(':', '/', $name)))) {
                $missing[] = \sprintf('%s (first used in %s)', $name, $file);
            }
        }

        self::assertSame([], $missing, 'Icons missing in assets/icons/. Run bin/console ux:icons:import for each.');
    }

    /**
     * @return list<string>
     */
    private function iconSetPrefixes(string $iconDir): array
    {
        $prefixes = [];
        foreach (new Finder()->directories()->in($iconDir)->depth(0) as $dir) {
            $prefixes[] = $dir->getFilename();
        }

        return $prefixes;
    }

    /**
     * @param list<string> $prefixes
     *
     * @return array<string, string> icon name => relative path of the first file using it
     */
    private function usedIconNames(string $root, array $prefixes): array
    {
        $pattern = \sprintf(
            '/(?<![a-z0-9-])(?:%s):[a-z0-9]+(?:-[a-z0-9]+)*/',
            implode('|', array_map(static fn (string $prefix): string => preg_quote($prefix, '/'), $prefixes)),
        );

        $finder = new Finder()
            ->files()
            ->in(array_map(static fn (string $dir): string => $root.'/'.$dir, self::SCANNED_DIRS))
            ->exclude(['vendor', 'icons'])
            ->name(['*.php', '*.twig', '*.yaml', '*.js']);

        $names = [];
        foreach ($finder as $file) {
            preg_match_all($pattern, $file->getContents(), $matches);
            foreach ($matches[0] as $name) {
                $names[$name] ??= substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        ksort($names);

        return $names;
    }
}
