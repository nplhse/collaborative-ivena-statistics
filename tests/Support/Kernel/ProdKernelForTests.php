<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use App\Kernel;

/**
 * The prod kernel as tests boot it (ContentSecurityPolicyTest), with its own cache directory, so the container
 * never mixes with a real prod cache in var/cache/prod. bin/warmup-test-kernels compiles it before the tests in CI
 * (#1343). A non-debug kernel does not notice changed files, so tests that did not get a prewarmed cache remove
 * the directory before they boot it.
 */
final class ProdKernelForTests extends Kernel
{
    public function __construct()
    {
        parent::__construct('prod', false);
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/prod_for_tests';
    }

    #[\Override]
    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }
}
