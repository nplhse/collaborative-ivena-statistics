<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

final class CommentExcerpt
{
    public const int LENGTH = 120;

    public static function from(string $content): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim(strip_tags($content))) ?? '';
        if (mb_strlen($normalized) <= self::LENGTH) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, self::LENGTH - 1)).'…';
    }
}
