<?php

declare(strict_types=1);

namespace App\Tests\Support\PropertyInfo;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * In-memory property info cache that lives as long as the PHP process.
 *
 * In the debug test kernel Symfony drops the property info cache, so every Live Component
 * render parses the same docblocks again. Tests boot a new kernel per test, so a container
 * pool would start empty each time; this static pool keeps the results across kernels.
 * It never outlives the process, so a changed docblock is always read fresh in the next run.
 */
final class ProcessPropertyInfoCache
{
    private static ?ArrayAdapter $pool = null;

    public static function pool(): CacheItemPoolInterface
    {
        return self::$pool ??= new ArrayAdapter();
    }
}
