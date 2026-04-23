<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\DependencyInjection;

use App\Shared\Infrastructure\DependencyInjection\AppConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class AppConfigurationTest extends TestCase
{
    public function testBlogDefaultsAreApplied(): void
    {
        $config = [
            'title' => 'App title',
            'short_title' => 'APP',
            'default_locale' => 'en',
        ];

        $processed = new Processor()->processConfiguration(new AppConfiguration(), [$config]);

        self::assertSame('Blog', $processed['blog']['title']);
        self::assertSame('Neuigkeiten, Updates und Hintergrundinformationen.', $processed['blog']['description']);
        self::assertSame('csv', $processed['import']['reject_writer']);
    }
}
