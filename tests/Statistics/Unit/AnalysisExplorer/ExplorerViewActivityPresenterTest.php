<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Shared\Application\DateTime\RelativeTimestampFormatter;
use App\Statistics\AnalysisExplorer\Application\ExplorerViewActivityPresenter;
use App\Statistics\Domain\Entity\SavedExplorerView;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerViewActivityPresenterTest extends TestCase
{
    public function testUsesCreationTimeUntilTheViewChanges(): void
    {
        $createdAt = new \DateTimeImmutable('2026-09-23 11:59:40', new \DateTimeZone('Europe/Berlin'));
        $view = $this->viewWithTimestamps($createdAt, $createdAt);

        $activity = $this->presenter()->present($view);

        self::assertSame('created', $activity['kind']);
        self::assertSame('time.relative.just_now', $activity['relativeLabel']);
        self::assertSame('2026-09-23T11:59:40+02:00', $activity['iso8601']);
    }

    public function testUsesTheLaterUpdateTime(): void
    {
        $view = $this->viewWithTimestamps(
            new \DateTimeImmutable('2026-09-01 10:00:00', new \DateTimeZone('Europe/Berlin')),
            new \DateTimeImmutable('2026-09-23 11:00:00', new \DateTimeZone('Europe/Berlin')),
        );

        $activity = $this->presenter()->present($view);

        self::assertSame('updated', $activity['kind']);
        self::assertSame('time.relative.hour', $activity['relativeLabel']);
        self::assertSame('2026-09-23T11:00:00+02:00', $activity['iso8601']);
    }

    private function presenter(): ExplorerViewActivityPresenter
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');
        $translator->method('trans')->willReturnArgument(0);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-09-23 12:00:00', new \DateTimeZone('Europe/Berlin')));

        return new ExplorerViewActivityPresenter(new RelativeTimestampFormatter($translator, $clock));
    }

    private function viewWithTimestamps(\DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt): SavedExplorerView
    {
        $view = new SavedExplorerView(null, 'Private hint', 'My views', ['schemaVersion' => 4]);
        new \ReflectionProperty(SavedExplorerView::class, 'createdAt')->setValue($view, $createdAt);
        new \ReflectionProperty(SavedExplorerView::class, 'updatedAt')->setValue($view, $updatedAt);

        return $view;
    }
}
