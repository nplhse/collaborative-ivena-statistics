<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Application;

use App\Content\Application\Contract\HelpFeedbackStatsQueryInterface;
use App\Content\Application\Event\HelpArticleCommented;
use App\Content\Application\Help\HelpFeedbackService;
use App\Content\Application\Help\HelpNavigation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Feedback\Domain\Entity\Feedback;
use App\Tests\Support\Content\HelpPages;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class HelpFeedbackServiceTest extends DatabaseKernelTestCase
{
    public function testOnlyHelpArticlesCanBeAnsweredOrCommented(): void
    {
        self::bootKernel();
        $page = PageFactory::createOne(['title' => 'About', 'slug' => 'about', 'path' => '/about']);
        $user = UserFactory::createOne();
        $service = self::getContainer()->get(HelpFeedbackService::class);

        $service->comment($user, $page, 'About', 'http://localhost/about', 'en', 'Something is missing here.');
        self::assertSame([], $this->feedback(), 'comments on other pages are ignored');

        $this->expectException(\InvalidArgumentException::class);
        $service->answer($user, $page, true, 'en');
    }

    public function testCountsAreKeptForTheRequest(): void
    {
        self::bootKernel();
        $article = HelpPages::article(HelpPages::root(), 'Export', '/help/export', '<p>Text</p>');
        $service = self::getContainer()->get(HelpFeedbackService::class);
        $service->answer(UserFactory::createOne(), $article, true, 'en');
        $service->answer(UserFactory::createOne(), $article, false, 'de');
        $stats = self::getContainer()->get(HelpFeedbackStatsQueryInterface::class);

        $counts = $stats->countsByPage();
        $service->answer(UserFactory::createOne(), $article, true, 'en');

        self::assertSame([(int) $article->getId() => ['helpful' => 1, 'unhelpful' => 1]], $counts);
        self::assertSame($counts, $stats->countsByPage(), 'one query per request');
    }

    public function testAnArticleOutsideTheHelpTreeHasNoNeighbours(): void
    {
        self::bootKernel();
        HelpPages::article(HelpPages::root(), 'Export', '/help/export', '<p>Text</p>');
        self::getContainer()->get(RequestStack::class)->push(Request::create('/'));

        self::assertSame(['previous' => null, 'next' => null], self::getContainer()->get(HelpNavigation::class)->neighbours(999999));
    }

    public function testCommentsOfUnknownUsersAndRejectedCommentsAreDropped(): void
    {
        self::bootKernel();
        $article = HelpPages::article(HelpPages::root(), 'Export', '/help/export', '<p>Text</p>');
        $user = UserFactory::createOne();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        $dispatcher->dispatch(new HelpArticleCommented((int) $article->getId(), 999999, 'Export', 'http://localhost/help/export', 'en', 'A valid comment.'));
        $dispatcher->dispatch(new HelpArticleCommented((int) $article->getId(), (int) $user->getId(), 'Export', 'http://localhost/help/export', 'en', str_repeat('x', 5000)));

        self::assertSame([], $this->feedback());
    }

    /**
     * @return list<Feedback>
     */
    private function feedback(): array
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(Feedback::class)->findAll();
    }
}
