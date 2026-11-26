<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

final readonly class OpensByIdTarget
{
    /**
     * @param class-string $entity
     */
    public function __construct(
        public string $route,
        public string $entity,
        public string $parameter,
    ) {
    }
}
