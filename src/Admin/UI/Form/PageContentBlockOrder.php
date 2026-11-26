<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

/**
 * Order of page content blocks in the editor.
 *
 * The editor keeps the field names (keys) of a block for the whole editing session, so moving a block never renames
 * its fields and nested prototypes such as accordion items keep pointing at the right block. The visible order
 * travels in a separate `position` field per block and is applied after the form has mapped the blocks: to the
 * stored list, to the numbering of validation messages and to the re-rendered form.
 */
final class PageContentBlockOrder
{
    public const string POSITION_FIELD = 'position';

    /**
     * Returns the keys ordered by position. Keys without a valid position keep their order after the positioned ones.
     *
     * @param array<array-key, mixed> $positions submitted position per block key
     *
     * @return list<array-key>
     */
    public static function sortKeys(array $positions): array
    {
        $entries = [];
        $index = 0;
        foreach ($positions as $key => $value) {
            $entries[] = ['key' => $key, 'position' => self::position($value) ?? \PHP_INT_MAX, 'index' => $index++];
        }

        usort($entries, static fn (array $a, array $b): int => [$a['position'], $a['index']] <=> [$b['position'], $b['index']]);

        return array_map(static fn (array $entry): int|string => $entry['key'], $entries);
    }

    /**
     * @template TBlock
     *
     * @param array<array-key, TBlock> $blocks
     * @param array<array-key, mixed>  $positions
     *
     * @return list<TBlock>
     */
    public static function sortBlocks(array $blocks, array $positions): array
    {
        $sorted = [];
        foreach (self::sortKeys($positions + array_fill_keys(array_keys($blocks), null)) as $key) {
            if (array_key_exists($key, $blocks)) {
                $sorted[] = $blocks[$key];
            }
        }

        return $sorted;
    }

    private static function position(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && '' !== $value && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
