<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use Cois\Plugin\EdVisit\EdVisitBundle;

/**
 * The application with the ED visit plugin disabled; only the schemas of the test bundle remain.
 */
final class KernelWithoutEdVisit extends KernelWithoutBundles
{
    #[\Override]
    protected function withoutBundles(): array
    {
        return [EdVisitBundle::class];
    }

    #[\Override]
    protected function cacheSuffix(): string
    {
        return 'without_ed_visit';
    }
}
