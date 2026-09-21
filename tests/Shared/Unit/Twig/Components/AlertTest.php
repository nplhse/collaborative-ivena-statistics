<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\UI\Twig\Components\Alert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AlertTest extends TestCase
{
    #[DataProvider('variantProvider')]
    public function testVariantMapping(string $type, string $expectedVariant): void
    {
        $alert = new Alert();
        $alert->type = $type;

        self::assertSame($expectedVariant, $alert->getVariant());
        self::assertStringContainsString('alert-'.$expectedVariant, $alert->getCssClass());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function variantProvider(): iterable
    {
        yield 'info' => ['info', 'info'];
        yield 'success' => ['success', 'success'];
        yield 'warning' => ['warning', 'warning'];
        yield 'danger' => ['danger', 'danger'];
        yield 'error alias' => ['error', 'danger'];
        yield 'validation alias' => ['validation', 'danger'];
        yield 'unknown fallback' => ['notice', 'info'];
        yield 'empty fallback' => ['', 'info'];
        yield 'uppercase alias' => ['ERROR', 'danger'];
    }

    #[DataProvider('roleProvider')]
    public function testRoleForVariant(string $type, string $expectedRole): void
    {
        $alert = new Alert();
        $alert->type = $type;

        self::assertSame($expectedRole, $alert->getRole());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function roleProvider(): iterable
    {
        yield 'info is status' => ['info', 'status'];
        yield 'success is status' => ['success', 'status'];
        yield 'warning is alert' => ['warning', 'alert'];
        yield 'danger is alert' => ['danger', 'alert'];
        yield 'error alias is alert' => ['error', 'alert'];
    }

    public function testDefaultTypeIsInfo(): void
    {
        $alert = new Alert();

        self::assertSame('info', $alert->type);
        self::assertSame('info', $alert->getVariant());
        self::assertSame('alert alert-info', $alert->getCssClass());
    }

    public function testDismissibleAndImportantCssClasses(): void
    {
        $alert = new Alert();
        $alert->type = 'success';
        $alert->dismissible = true;
        $alert->important = true;

        self::assertSame('alert alert-success alert-dismissible alert-important', $alert->getCssClass());
    }

    #[DataProvider('autoIconProvider')]
    public function testAutoIconFollowsVariant(string $type, string $expectedIcon): void
    {
        $alert = new Alert();
        $alert->type = $type;

        self::assertSame($expectedIcon, $alert->getIconName());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function autoIconProvider(): iterable
    {
        yield 'info' => ['info', 'tabler:info-circle'];
        yield 'success' => ['success', 'tabler:circle-check'];
        yield 'warning' => ['warning', 'tabler:alert-triangle'];
        yield 'danger' => ['danger', 'tabler:alert-circle'];
        yield 'error alias uses danger icon' => ['error', 'tabler:alert-circle'];
        yield 'validation alias uses danger icon' => ['validation', 'tabler:alert-circle'];
        yield 'unknown fallback uses info icon' => ['notice', 'tabler:info-circle'];
    }

    public function testNoneIconDisablesIcon(): void
    {
        $alert = new Alert();
        $alert->icon = 'none';

        self::assertNull($alert->getIconName());
    }

    public function testEmptyIconDisablesIcon(): void
    {
        $alert = new Alert();
        $alert->icon = '';

        self::assertNull($alert->getIconName());
    }

    public function testCustomIconNameIsKept(): void
    {
        $alert = new Alert();
        $alert->icon = 'tabler:notes';

        self::assertSame('tabler:notes', $alert->getIconName());
    }
}
