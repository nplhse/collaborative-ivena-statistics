<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\DependencyInjection;

use App\Shared\Infrastructure\DependencyInjection\AppExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AppExtensionTest extends TestCase
{
    public function testLoadRegistersBlogParameters(): void
    {
        $container = new ContainerBuilder();

        new AppExtension()->load([
            [
                'title' => 'Collaborative IVENA statistics',
                'short_title' => 'COIS',
                'default_locale' => 'en',
                'blog' => [
                    'title' => 'Our Blog',
                    'description' => 'Blog Description',
                ],
                'import' => [
                    'reject_writer' => 'csv',
                    'csv_reject_dir' => 'var/import_rejects',
                ],
                'meta' => [
                    'copyright_start_year' => 2024,
                    'hoster' => [
                        'name' => 'Uberspace',
                        'url' => 'https://uberspace.de',
                    ],
                ],
            ],
        ], $container);

        self::assertSame('Our Blog', $container->getParameter('app.blog.title'));
        self::assertSame('Blog Description', $container->getParameter('app.blog.description'));
        self::assertSame('COIS', $container->getParameter('app.short_title'));
        self::assertSame(2024, $container->getParameter('app.meta.copyright_start_year'));
        self::assertSame('Uberspace', $container->getParameter('app.meta.hoster.name'));
        self::assertSame('https://uberspace.de', $container->getParameter('app.meta.hoster.url'));
    }
}
