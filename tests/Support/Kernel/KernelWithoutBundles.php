<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use App\Kernel;

/**
 * The application without some bundles of config/bundles.php (#1300 step 5), each variant with its own cache
 * directory so its container never mixes with the regular one. The database is the regular test database: the
 * tables of a removed bundle stay, as they would after disabling the bundle in a deployment.
 */
abstract class KernelWithoutBundles extends Kernel
{
    /**
     * @return list<class-string> bundle classes left out
     */
    abstract protected function withoutBundles(): array;

    abstract protected function cacheSuffix(): string;

    #[\Override]
    public function registerBundles(): iterable
    {
        foreach (parent::registerBundles() as $bundle) {
            if (!\in_array($bundle::class, $this->withoutBundles(), true)) {
                yield $bundle;
            }
        }
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return parent::getCacheDir().'_'.$this->cacheSuffix();
    }

    #[\Override]
    public function getBuildDir(): string
    {
        return parent::getBuildDir().'_'.$this->cacheSuffix();
    }
}
