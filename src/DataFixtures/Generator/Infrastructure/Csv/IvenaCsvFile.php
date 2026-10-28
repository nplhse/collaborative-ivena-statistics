<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Csv;

/**
 * Writes an IVENA-like CSV file: UTF-8, `;` as separator, every field in double quotes.
 */
final readonly class IvenaCsvFile
{
    /** Free text / remark of every row of a demo file (#791). */
    public const string SYNTHETIC_REMARK = 'SYNTHETIC DEMO DATA (#791)';

    /**
     * @param list<string>           $header
     * @param iterable<list<string>> $rows
     *
     * @return int number of rows written (without the header)
     */
    public static function write(string $path, array $header, iterable $rows): int
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s".', $directory));
        }

        $handle = fopen($path, 'w');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Cannot write "%s".', $path));
        }

        try {
            fwrite($handle, self::line($header));
            $count = 0;
            foreach ($rows as $row) {
                fwrite($handle, self::line($row));
                ++$count;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /**
     * @param list<string> $fields
     */
    public static function line(array $fields): string
    {
        return implode(';', array_map(static fn (string $field): string => '"'.str_replace('"', '""', $field).'"', $fields))."\n";
    }
}
