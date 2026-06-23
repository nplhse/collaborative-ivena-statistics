<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Export;

final class ExportFilenameFactory
{
    private const int MAX_LENGTH = 80;

    public function create(string $title, string $extension): string
    {
        $slug = strtolower(trim($title));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        if ('' === $slug) {
            $slug = 'analysis-export';
        }

        $date = new \DateTimeImmutable('today')->format('Y-m-d');
        $suffix = sprintf('-%s.%s', $date, ltrim($extension, '.'));

        $maxSlugLength = max(1, self::MAX_LENGTH - \strlen($suffix));
        if (\strlen($slug) > $maxSlugLength) {
            $slug = rtrim(substr($slug, 0, $maxSlugLength), '-');
        }

        return $slug.$suffix;
    }
}
