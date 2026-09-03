<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

interface ProjectActivityQueryInterface
{
    public function getPage(
        ?string $cursor,
        int $limit = ProjectActivityPage::PAGE_SIZE,
        ?ProjectActivityFilters $filters = null,
    ): ProjectActivityPage;
}
