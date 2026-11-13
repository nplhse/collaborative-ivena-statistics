<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Tool;

/**
 * @psalm-suppress PossiblyUnusedProperty Read by the Tools landing page template.
 */
final readonly class ToolGroup
{
    /**
     * @param non-empty-list<Tool> $tools
     */
    public function __construct(
        public ToolCategory $category,
        public array $tools,
    ) {
    }
}
