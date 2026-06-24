<?php

declare(strict_types=1);

namespace App\Content\Application\Slug;

use Symfony\Component\String\Slugger\SluggerInterface;

final readonly class SlugGenerator
{
    public const int MAX_LENGTH_POST = 200;
    public const int MAX_LENGTH_PAGE = 180;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private SluggerInterface $slugger,
    ) {
    }

    public function normalize(string $value, int $maxLength = self::MAX_LENGTH_POST): string
    {
        $slug = strtolower($this->slugger->slug($value)->toString());

        if ('' === $slug) {
            throw new \InvalidArgumentException('Slug must not be empty.');
        }

        return $this->truncate($slug, $maxLength);
    }

    /**
     * @param callable(string): bool $exists
     */
    public function ensureUnique(string $base, callable $exists, int $maxLength = self::MAX_LENGTH_POST): string
    {
        $candidate = $base;
        $counter = 2;

        while ($exists($candidate)) {
            $suffix = '-'.$counter;
            $truncatedBase = $this->truncate($base, $maxLength - strlen($suffix));
            $candidate = $truncatedBase.$suffix;
            ++$counter;
        }

        return $candidate;
    }

    private function truncate(string $slug, int $maxLength): string
    {
        if (strlen($slug) <= $maxLength) {
            return $slug;
        }

        return rtrim(substr($slug, 0, $maxLength), '-');
    }
}
