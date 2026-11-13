<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class BreadcrumbsComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testTranslatesALabelFromAnExplicitDomainOutsideTheKnownPrefixes(): void
    {
        // #1086: "notification." is not a known prefix; the explicit domain alone must be enough.
        $rendered = $this->renderTwigComponent('Breadcrumbs', [
            'items' => [
                ['label' => 'notification.title', 'label_domain' => 'notification'],
            ],
        ]);

        $active = $rendered->crawler()->filter('.breadcrumb-item.active');
        self::assertSame('Notifications', trim($active->text()));
    }

    public function testPrintsPlainTextLabelsAsTheyAre(): void
    {
        $rendered = $this->renderTwigComponent('Breadcrumbs', [
            'items' => [
                ['label' => 'Kiel Klinikum'],
            ],
        ]);

        self::assertSame('Kiel Klinikum', trim($rendered->crawler()->filter('.breadcrumb-item.active')->text()));
    }
}
