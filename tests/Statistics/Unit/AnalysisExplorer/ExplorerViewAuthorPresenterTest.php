<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerViewAuthorPresenter;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class ExplorerViewAuthorPresenterTest extends TestCase
{
    public function testHidesTheAuthorForAMissingOrSystemView(): void
    {
        $presenter = $this->presenter();

        self::assertSame(['name' => null, 'url' => null], $presenter->present(null));
        self::assertSame(
            ['name' => null, 'url' => null],
            $presenter->present(new SavedExplorerView('allocations-over-time', 'System', 'Allocations', [], null, true)),
        );
    }

    public function testHidesTheAuthorWhenTheCreatorOrUsernameIsMissing(): void
    {
        $presenter = $this->presenter();
        $withoutCreator = new SavedExplorerView(null, 'Orphan', 'My views', []);
        $withoutUsername = new SavedExplorerView(null, 'Nameless', 'My views', []);
        $withoutUsername->setCreatedBy(new User());

        self::assertSame(['name' => null, 'url' => null], $presenter->present($withoutCreator));
        self::assertSame(['name' => null, 'url' => null], $presenter->present($withoutUsername));
    }

    public function testLinksANamedCreatorToTheirProfile(): void
    {
        $publicId = Uuid::v4();
        $user = new User();
        $user->setUsername('ada');
        $user->setPublicId($publicId);
        $view = new SavedExplorerView(null, 'Shared', 'My views', []);
        $view->setCreatedBy($user);

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())
            ->method('generate')
            ->with('app_explore_user_show', ['publicId' => $publicId->toRfc4122()])
            ->willReturn('/explore/users/'.$publicId->toRfc4122());

        self::assertSame(
            ['name' => 'ada', 'url' => '/explore/users/'.$publicId->toRfc4122()],
            new ExplorerViewAuthorPresenter($urls)->present($view),
        );
    }

    public function testKeepsTheNameWhenTheCreatorHasNoPublicId(): void
    {
        $user = new User();
        $user->setUsername('ada');
        $view = new SavedExplorerView(null, 'Shared', 'My views', []);
        $view->setCreatedBy($user);

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::never())->method('generate');

        self::assertSame(
            ['name' => 'ada', 'url' => null],
            new ExplorerViewAuthorPresenter($urls)->present($view),
        );
    }

    private function presenter(): ExplorerViewAuthorPresenter
    {
        return new ExplorerViewAuthorPresenter($this->createStub(UrlGeneratorInterface::class));
    }
}
