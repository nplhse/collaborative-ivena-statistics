<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerViewAuthorPresenter;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ExplorerViewAuthorPresenterTest extends TestCase
{
    public function testHidesTheAuthorForAMissingOrSystemView(): void
    {
        $presenter = $this->presenter();

        self::assertSame(['name' => null, 'displayName' => null, 'userId' => null, 'url' => null], $presenter->present(null));
        self::assertSame(
            ['name' => null, 'displayName' => null, 'userId' => null, 'url' => null],
            $presenter->present(new SavedExplorerView('allocations-over-time', 'System', 'Allocations', [], null, true)),
        );
    }

    public function testHidesTheAuthorWhenTheCreatorOrUsernameIsMissing(): void
    {
        $presenter = $this->presenter();
        $withoutCreator = new SavedExplorerView(null, 'Orphan', 'My views', []);
        $withoutUsername = new SavedExplorerView(null, 'Nameless', 'My views', []);
        $withoutUsername->setCreatedBy(new User());

        self::assertSame(['name' => null, 'displayName' => null, 'userId' => null, 'url' => null], $presenter->present($withoutCreator));
        self::assertSame(['name' => null, 'displayName' => null, 'userId' => null, 'url' => null], $presenter->present($withoutUsername));
    }

    public function testLinksANamedCreatorToTheirProfile(): void
    {
        $user = new User();
        $user->setUsername('ada');
        $user->setDisplayName('Ada Lovelace');
        $view = new SavedExplorerView(null, 'Shared', 'My views', []);
        $view->setCreatedBy($user);

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())
            ->method('generate')
            ->with('app_explore_user_show', ['username' => 'ada'])
            ->willReturn('/explore/user/ada');

        self::assertSame(
            ['name' => 'ada', 'displayName' => 'Ada Lovelace', 'userId' => null, 'url' => '/explore/user/ada'],
            new ExplorerViewAuthorPresenter($urls)->present($view),
        );
    }

    private function presenter(): ExplorerViewAuthorPresenter
    {
        return new ExplorerViewAuthorPresenter($this->createStub(UrlGeneratorInterface::class));
    }
}
