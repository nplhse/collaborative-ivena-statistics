<?php

declare(strict_types=1);

namespace App\Tests\Support\Foundry;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
abstract class DatabaseWebTestCase extends WebTestCase
{
    use Factories;
}
