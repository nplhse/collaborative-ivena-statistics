<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageImageContentAnalyzer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
abstract class PageImageContentAnalyzerTestCase extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function analyzer(): PageImageContentAnalyzer
    {
        return self::getContainer()->get(PageImageContentAnalyzer::class);
    }
}
