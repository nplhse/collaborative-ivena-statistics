<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ModalComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersTablerShellAndContentSlot(): void
    {
        $rendered = $this->renderTwigComponent(
            'Modal',
            [
                'id' => 'stats-benchmark-selection-modal',
                'title' => 'Edit selection',
                'size' => 'xl',
                'data-testid' => 'stats-benchmark-selection-modal',
            ],
            '<div class="modal-body">Form body</div>',
        );

        $html = (string) $rendered;

        self::assertStringContainsString('modal modal-blur fade', $html);
        self::assertStringContainsString('id="stats-benchmark-selection-modal"', $html);
        self::assertStringContainsString('aria-labelledby="stats-benchmark-selection-modal-label"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable', $html);
        self::assertStringContainsString('id="stats-benchmark-selection-modal-label"', $html);
        self::assertStringContainsString('Edit selection', $html);
        self::assertStringContainsString('data-bs-dismiss="modal"', $html);
        self::assertStringContainsString('Form body', $html);
        self::assertGreaterThan(
            0,
            $rendered->crawler()->filter('[data-testid="stats-benchmark-selection-modal"]')->count(),
        );
    }

    public function testConfirmModalRendersDestructivePostForm(): void
    {
        $html = (string) $this->renderTwigComponent('ConfirmModal', [
            'id' => 'import-delete-modal',
            'title' => 'Delete import',
            'message' => 'Delete this import?',
            'action' => '/import/4/delete',
            'csrfToken' => 'token-value',
            'confirmLabel' => 'Delete',
            'cancelLabel' => 'Cancel',
        ]);

        self::assertStringContainsString('id="import-delete-modal"', $html);
        self::assertStringContainsString('modal-dialog modal-sm modal-dialog-centered', $html);
        self::assertStringNotContainsString('modal-dialog-scrollable', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('action="/import/4/delete"', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringContainsString('value="token-value"', $html);
        self::assertStringContainsString('Delete this import?', $html);
        self::assertStringContainsString('btn btn-link link-secondary', $html);
        self::assertStringContainsString('btn btn-danger', $html);
        self::assertStringContainsString('>Delete<', $html);
        self::assertStringContainsString('>Cancel<', $html);
    }
}
