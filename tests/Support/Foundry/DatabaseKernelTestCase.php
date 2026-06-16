<?php

declare(strict_types=1);

namespace App\Tests\Support\Foundry;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

abstract class DatabaseKernelTestCase extends KernelTestCase
{
    use Factories;
    use ResetDatabase;
}
