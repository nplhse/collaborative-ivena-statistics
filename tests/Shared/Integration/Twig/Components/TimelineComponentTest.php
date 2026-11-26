<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class TimelineComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testItemWithoutDetailsIsAPlainRowWithVisibleCategoryAndBothTimes(): void
    {
        $rendered = $this->renderTwigComponent('TimelineItem', [
            'icon' => 'tabler:pencil',
            'category' => 'Changed',
            'time' => new \DateTimeImmutable('2026-11-13 09:00:05', new \DateTimeZone('UTC')),
            'tone' => 'danger',
            'data-testid' => 'item',
        ], 'Hospital #12');

        $item = $rendered->crawler()->filter('[data-testid="item"]');
        self::assertSame('li', $item->nodeName());
        self::assertStringContainsString('timeline-tone-danger', (string) $item->attr('class'));
        self::assertCount(0, $item->filter('details'), 'nothing to expand');
        self::assertSame('Changed', $item->filter('.timeline-category')->text());
        self::assertSame('2026-11-13T09:00:05+00:00', $item->filter('time')->attr('datetime'));
        self::assertSame('13.11.2026 10:00:05', $item->filter('time')->text(), 'shown in Europe/Berlin');
        self::assertStringContainsString('Hospital #12', $item->text());
        self::assertSame('true', $item->filter('.timeline-marker')->attr('aria-hidden'));
    }

    public function testItemWithDetailsUrlIsExpandableAndKeepsAFallbackLink(): void
    {
        $rendered = $this->renderTwigComponent('TimelineItem', [
            'category' => 'Changed',
            'detailsUrl' => '/admin/audit/entries/5/details',
            'detailsLabel' => 'Show details',
            'detailsErrorLabel' => 'Failed',
        ], 'Summary');

        $details = $rendered->crawler()->filter('details');
        self::assertSame('timeline-details', $details->attr('data-controller'));
        self::assertSame('/admin/audit/entries/5/details', $details->attr('data-timeline-details-url-value'));
        self::assertSame('Failed', $details->attr('data-timeline-details-error-text-value'));
        self::assertNull($details->attr('open'));
        self::assertStringContainsString('Summary', $details->filter('summary')->text());
        $region = $details->filter('[data-timeline-details-target="region"]');
        self::assertSame('polite', $region->attr('aria-live'));
        self::assertSame('/admin/audit/entries/5/details', $region->filter('a')->attr('href'));
    }

    public function testUnknownToneFallsBackAndContentIsEscapedByTheCaller(): void
    {
        $html = (string) $this->renderTwigComponent('TimelineItem', ['tone' => 'purple', 'category' => '<b>x</b>']);

        self::assertStringContainsString('timeline-tone-default', $html);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['Mars/Olympus'])]
    #[\PHPUnit\Framework\Attributes\TestWith([''])]
    public function testUnknownOrEmptyTimezoneFallsBackToBerlin(string $timezone): void
    {
        $html = (string) $this->renderTwigComponent('TimelineItem', [
            'time' => new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
            'timezone' => $timezone,
        ]);

        self::assertStringContainsString('01.07.2026 12:00:00', $html);
    }

    public function testTimelineIsALabelledOrderedList(): void
    {
        $rendered = $this->renderTwigComponent('Timeline', ['label' => 'Audit', 'nested' => true]);

        $list = $rendered->crawler()->filter('ol');
        self::assertSame('Audit', $list->attr('aria-label'));
        self::assertStringContainsString('timeline-list-nested', (string) $list->attr('class'));
    }
}
