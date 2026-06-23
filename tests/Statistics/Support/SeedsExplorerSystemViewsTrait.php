<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Support;

use App\Statistics\AnalysisExplorer\Application\ExplorerSystemViewSeeder;
use App\User\Domain\Factory\UserFactory;

trait SeedsExplorerSystemViewsTrait
{
    protected function seedExplorerSystemViews(): void
    {
        UserFactory::createOne([
            'username' => 'admin',
            'roles' => ['ROLE_USER', 'ROLE_ADMIN'],
        ]);

        static::getContainer()->get(ExplorerSystemViewSeeder::class)->sync();
    }
}
