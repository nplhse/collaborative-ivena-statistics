<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\UI\Twig\Components\Modal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModalTest extends TestCase
{
    public function testLabelIdUsesComponentId(): void
    {
        $modal = new Modal();
        $modal->id = 'import-delete-modal';

        self::assertSame('import-delete-modal-label', $modal->getLabelId());
    }

    #[DataProvider('dialogClassProvider')]
    public function testDialogClass(string $size, bool $scrollable, string $expected): void
    {
        $modal = new Modal();
        $modal->id = 'example-modal';
        $modal->size = $size;
        $modal->scrollable = $scrollable;

        self::assertSame($expected, $modal->getDialogClass());
    }

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: string}>
     */
    public static function dialogClassProvider(): iterable
    {
        yield 'default' => ['', true, 'modal-dialog modal-dialog-centered modal-dialog-scrollable'];
        yield 'small confirmation' => ['sm', false, 'modal-dialog modal-sm modal-dialog-centered'];
        yield 'large' => ['lg', true, 'modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable'];
        yield 'extra large' => ['xl', true, 'modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable'];
        yield 'unknown size' => ['md', true, 'modal-dialog modal-dialog-centered modal-dialog-scrollable'];
    }
}
