<?php

declare(strict_types=1);

namespace App\Tests\Support\Foundry;

use App\Statistics\Infrastructure\Query\Overview\OverviewMaterializedViewsInstaller;
use Symfony\Component\HttpKernel\CacheClearer\Psr6CacheClearer;
use Symfony\Component\HttpKernel\KernelInterface;
use Zenstruck\Foundry\ORM\ResetDatabase\OrmResetter;

/**
 * Ensures overview materialized views exist after Foundry's migrate reset.
 *
 * With reset mode `migrate`, the database is dropped and recreated via migrations (which
 * create the views). MV DROP before reset is not needed and would fail when the worker
 * database does not exist yet (e.g. ParaTest with TEST_TOKEN).
 *
 * Decorated inner to Foundry's DamaDatabaseResetter so DAMA disables static connections
 * before the migrate reset runs. The reset terminates DAMA's static connections opened by
 * earlier tests, so they are dropped afterwards ({@see DamaStaticConnections}, #887).
 *
 * @see tests/Support/MaterializedView/README.md
 */
final readonly class MaterializedViewAwareOrmResetter implements OrmResetter
{
    public function __construct(
        private OrmResetter $decorated,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
        private Psr6CacheClearer $cacheClearer,
    ) {
    }

    public function resetBeforeFirstTest(KernelInterface $kernel): void
    {
        $this->materializedViewsInstaller->resetInstallationState();
        $this->decorated->resetBeforeFirstTest($kernel);
        // The reset terminated every other backend, including DAMA's static connections.
        DamaStaticConnections::forgetAll();
        $this->materializedViewsInstaller->ensureInstalled();
        $this->clearExploreFilterReferenceCache();
    }

    public function resetBeforeEachTest(KernelInterface $kernel): void
    {
        $this->materializedViewsInstaller->resetInstallationState();
        $this->decorated->resetBeforeEachTest($kernel);
        $this->materializedViewsInstaller->ensureInstalled();
        $this->clearExploreFilterReferenceCache();
    }

    private function clearExploreFilterReferenceCache(): void
    {
        $this->cacheClearer->clearPool('cache.allocation.reference_data');
    }
}
