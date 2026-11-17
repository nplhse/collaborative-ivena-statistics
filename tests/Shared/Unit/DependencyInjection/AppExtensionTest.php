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
                    'uberspace_credit' => false,
                ],
            ],
        ], $container);

        self::assertSame('Our Blog', $container->getParameter('app.blog.title'));
        self::assertSame('Blog Description', $container->getParameter('app.blog.description'));
        self::assertSame('en', $container->getParameter('app.content.default_locale'));
        self::assertSame('COIS', $container->getParameter('app.short_title'));
        self::assertSame(2024, $container->getParameter('app.meta.copyright_start_year'));
        self::assertFalse($container->getParameter('app.meta.uberspace_credit'));
        self::assertFalse($container->hasParameter('app.meta.hoster.name'));
        self::assertFalse($container->hasParameter('app.meta.hoster.url'));
        self::assertSame(['.ru'], $container->getParameter('app.registration.blocked_email_domains'));
    }

    public function testLoadRegistersCustomBlockedEmailDomains(): void
    {
        $container = new ContainerBuilder();

        new AppExtension()->load([
            [
                'title' => 'Collaborative IVENA statistics',
                'short_title' => 'COIS',
                'default_locale' => 'en',
                'registration' => [
                    'blocked_email_domains' => ['mailinator.com', '.ru'],
                ],
            ],
        ], $container);

        self::assertSame(
            ['mailinator.com', '.ru'],
            $container->getParameter('app.registration.blocked_email_domains'),
        );
    }

    public function testUberspaceCreditIsOnByDefault(): void
    {
        $container = new ContainerBuilder();

        new AppExtension()->load([
            [
                'title' => 'Collaborative IVENA statistics',
                'short_title' => 'COIS',
                'default_locale' => 'en',
            ],
        ], $container);

        self::assertTrue($container->getParameter('app.meta.uberspace_credit'));
    }
}
