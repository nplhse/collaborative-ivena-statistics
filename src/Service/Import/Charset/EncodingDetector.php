<?php

namespace App\Service\Import\Charset;

final class EncodingDetector
{
    public function detectFromPath(string $path, string $hint = 'auto'): string
    {
        if ('auto' !== $hint) {
            return $hint;
        }

        $chunk = @file_get_contents($path, false, null, 0, 8192);

        if (false === $chunk) {
            throw new \RuntimeException("Cannot read file: $path");
        }

        if ('' === $chunk) {
            throw new \RuntimeException("File is empty: $path");
        }

        if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }

        if (1 === \preg_match('//u', $chunk)) {
            return 'UTF-8';
        }

        if (1 === \preg_match('/[\x80-\xFF]/', $chunk)) {
            return 'ISO-8859-1';
        }

        return 'UTF-8';
    }
}
