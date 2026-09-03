<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

final class PostPublishedActivitySlugs
{
    /**
     * @param list<ProjectActivity|ProfileActivity> $activities
     *
     * @return list<string>
     */
    public static function from(array $activities): array
    {
        $slugs = [];
        foreach ($activities as $activity) {
            if (ProfileActivityType::POST_PUBLISHED !== $activity->type) {
                continue;
            }

            if (null === $activity->postSlug || '' === $activity->postSlug) {
                continue;
            }

            $slugs[] = $activity->postSlug;
        }

        return array_values(array_unique($slugs));
    }
}
