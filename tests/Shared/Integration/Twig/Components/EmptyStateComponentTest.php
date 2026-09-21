<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class EmptyStateComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersTitleDescriptionAndActions(): void
    {
        $html = (string) $this->renderTwigComponent(
            'EmptyState',
            [
                'title' => 'Nothing here',
                'description' => 'Try another filter',
                'icon' => 'tabler:users',
            ],
            '',
            ['actions' => '<a class="btn" href="/reset">Reset</a>'],
        );

        self::assertStringContainsString('empty-title', $html);
        self::assertStringContainsString('Nothing here', $html);
        self::assertStringContainsString('Try another filter', $html);
        self::assertStringContainsString('Reset', $html);
    }
}
