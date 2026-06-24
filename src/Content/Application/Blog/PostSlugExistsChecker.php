<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

interface PostSlugExistsChecker
{
    public function slugExists(string $slug, ?int $excludeId = null): bool;
}
