<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Support;

use App\Statistics\AnalysisExplorer\Application\ExplorerSystemViewSeeder;

trait SeedsExplorerSystemViewsTrait
{
    protected function seedExplorerSystemViews(): void
    {
        static::getContainer()->get(ExplorerSystemViewSeeder::class)->sync();
    }
}
