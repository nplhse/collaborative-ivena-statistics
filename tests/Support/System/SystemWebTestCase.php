<?php

declare(strict_types=1);

namespace App\Tests\Support\System;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Ensures each ParaTest worker migrates its isolated test database before system HTTP tests run.
 */
#[ResetDatabase]
abstract class SystemWebTestCase extends WebTestCase
{
}
