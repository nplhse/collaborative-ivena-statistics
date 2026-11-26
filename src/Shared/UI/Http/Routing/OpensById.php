<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Routing;

/**
 * Marks an Explore detail route as openable by internal ID for administrators (#1354).
 *
 * The route must look like `/explore/{type}/{parameter}` with exactly one path variable;
 * `/explore/{type}/{id}` then loads `$entity` by its numeric ID and redirects to this route,
 * filling the variable from the entity property of the same name (e.g. `publicId`, `username`).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class OpensById
{
    /**
     * @param class-string $entity
     */
    public function __construct(
        public string $entity,
    ) {
    }
}
