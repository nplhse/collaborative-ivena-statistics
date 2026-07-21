<?php

declare(strict_types=1);

namespace App\Content\Application\Contract;

interface MediaLibraryAdminUrlProviderInterface
{
    public function getIndexUrl(): string;
}
