<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Domain\Entity\Reaction;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ReactionControllerTest extends WebTestCase
{
    use Factories;

    private const string STREAM = 'text/vnd.turbo-stream.html';

    public function testBlogPostAndCommentsRenderPlaceholdersOnly(): void
    {
        $client = self::createClient();
        $post = PostFactory::createOne(['slug' => 'reaction-post']);
        $comment = PostCommentFactory::createOne(['post' => $post]);
        $this->react(UserFactory::createOne(), ReactionTargetType::Post, (int) $post->getId(), ReactionType::Like);

        $crawler = $client->request(Request::METHOD_GET, '/blog/reaction-post');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#reactions-post-'.$post->getId().'[data-controller="reactions-loader"]'));
        self::assertCount(1, $crawler->filter('#reactions-comment-'.$comment->getId().'[data-controller="reactions-loader"]'));
        self::assertCount(0, $crawler->filter('[data-testid="reaction-bar"]'), 'the page does not wait for reactions');
    }

    public function testVisitorsSeeCountsWithoutNamesOrControls(): void
    {
        $client = self::createClient();
        $post = PostFactory::createOne();
        $postId = (int) $post->getId();
        $this->react(UserFactory::createOne(['username' => 'anna']), ReactionTargetType::Post, $postId, ReactionType::Like);
        $this->react(UserFactory::createOne(['username' => 'ben']), ReactionTargetType::Post, $postId, ReactionType::Like);
        $this->react(UserFactory::createOne(['username' => 'cem']), ReactionTargetType::Post, $postId, ReactionType::Hooray);

        $bar = $this->bar($this->load($client, 'post:'.$postId), 'post', $postId);

        self::assertSame(['like' => '2', 'hooray' => '1'], $this->chips($bar));
        self::assertCount(0, $bar->filter('form'));
        self::assertCount(0, $bar->filter('[data-testid="reaction-add"]'));
        self::assertStringNotContainsString('anna', $bar->html());
        self::assertSame('Like: 2', $bar->filter('[data-reaction-type="like"]')->attr('title'));
    }

    public function testSignedInUsersSeeNamesTheirOwnReactionAndThePicker(): void
    {
        $client = self::createClient();
        $viewer = UserFactory::createOne(['username' => 'viewer']);
        $post = PostFactory::createOne();
        $postId = (int) $post->getId();
        $this->react(UserFactory::createOne(['username' => 'anna', 'displayName' => 'Anna A.']), ReactionTargetType::Post, $postId, ReactionType::Like);
        $this->react($viewer, ReactionTargetType::Post, $postId, ReactionType::Like);
        $client->loginUser($viewer);

        $bar = $this->bar($this->load($client, 'post:'.$postId), 'post', $postId);
        $like = $bar->filter('[data-reaction-type="like"]');

        self::assertSame('Like: Anna A., viewer', $like->attr('title'));
        self::assertSame('true', $like->attr('aria-pressed'));
        self::assertStringContainsString('reaction-chip--own', (string) $like->attr('class'));
        self::assertCount(8, $bar->filter('[data-testid="reaction-option"]'));
    }

    public function testInvisibleAndUnknownTargetsRemoveTheirPlaceholders(): void
    {
        $client = self::createClient();
        $draft = PostFactory::createOne(['status' => PostStatus::DRAFT]);
        $activity = $this->activity(UserFactory::createOne());

        $streams = $this->load($client, \sprintf('post:%d,activity:%d,post:999999,bogus', $draft->getId(), $activity));

        self::assertCount(0, $streams->filter('turbo-stream[action="replace"]'));
        self::assertSame(
            ['#reactions-post-'.$draft->getId(), '#reactions-activity-'.$activity, '#reactions-post-999999'],
            $streams->filter('turbo-stream[action="remove"]')->each(static fn (Crawler $node): string => (string) $node->attr('targets')),
            'visitors may not see activities; drafts and unknown ids stay hidden',
        );
    }

    public function testTogglingAnswersWithTheUpdatedBar(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne();
        $comment = PostCommentFactory::createOne(['post' => PostFactory::createOne()]);
        $commentId = (int) $comment->getId();
        $client->loginUser($user);

        $bar = $this->bar($this->load($client, 'comment:'.$commentId), 'comment', $commentId);
        $client->submit($bar->filter('[data-testid="reaction-option"][value="rocket"]')->form(), [], ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', self::STREAM.'; charset=UTF-8');
        $updated = $this->bar($client->getCrawler(), 'comment', $commentId);
        self::assertSame(['rocket' => '1'], $this->chips($updated));
        self::assertSame('true', $updated->filter('[data-reaction-type="rocket"]')->attr('aria-pressed'));

        $client->submit($updated->filter('[data-testid="reaction-chip"][value="rocket"]')->form(), [], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertSame([], $this->chips($this->bar($client->getCrawler(), 'comment', $commentId)), 'the same reaction again removes it');
    }

    public function testWithoutJavaScriptTheFormRedirectsBack(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne();
        $post = PostFactory::createOne(['slug' => 'plain-form']);
        $client->loginUser($user);

        $client->request(Request::METHOD_POST, '/reactions/post/'.$post->getId(), [
            'type' => 'love',
            '_token' => $this->token($client, 'post:'.$post->getId(), 'post', (int) $post->getId()),
        ], [], ['HTTP_REFERER' => 'http://localhost/blog/plain-form']);

        self::assertResponseRedirects('http://localhost/blog/plain-form', 303);
        self::assertSame(1, $this->storedReactions());
    }

    public function testForeignRefererFallsBackToTheHomepage(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne();
        $post = PostFactory::createOne();
        $client->loginUser($user);

        $client->request(Request::METHOD_POST, '/reactions/post/'.$post->getId(), [
            'type' => 'love',
            '_token' => $this->token($client, 'post:'.$post->getId(), 'post', (int) $post->getId()),
        ], [], ['HTTP_REFERER' => 'https://evil.example/']);

        self::assertResponseRedirects('/', 303);
    }

    public function testVisitorsCannotReact(): void
    {
        $client = self::createClient();
        $post = PostFactory::createOne();

        $client->request(Request::METHOD_POST, '/reactions/post/'.$post->getId(), ['type' => 'like']);

        self::assertResponseRedirects();
        self::assertSame(0, $this->storedReactions());
    }

    public function testRefusesInvalidTokenUnknownReactionAndHiddenTargets(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne();
        $post = PostFactory::createOne();
        $draft = PostFactory::createOne(['status' => PostStatus::DRAFT]);
        $client->loginUser($user);
        $token = $this->token($client, 'post:'.$post->getId(), 'post', (int) $post->getId());

        $client->request(Request::METHOD_POST, '/reactions/post/'.$post->getId(), ['type' => 'like', '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_POST, '/reactions/post/'.$post->getId(), ['type' => 'dislike', '_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_POST, '/reactions/post/'.$draft->getId(), ['type' => 'like', '_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, $this->storedReactions());
    }

    public function testNotificationLinkOpensThePost(): void
    {
        $client = self::createClient();
        $post = PostFactory::createOne(['slug' => 'open-post']);
        $client->loginUser(UserFactory::createOne());

        $client->request(Request::METHOD_GET, '/reactions/open/post/'.$post->getId());

        self::assertResponseRedirects('/blog/open-post');
    }

    public function testNotificationLinkOpensTheCommentOnItsPost(): void
    {
        $client = self::createClient();
        $post = PostFactory::createOne(['slug' => 'open-comment']);
        $comment = PostCommentFactory::createOne(['post' => $post]);
        $client->loginUser(UserFactory::createOne());

        $client->request(Request::METHOD_GET, '/reactions/open/comment/'.$comment->getId());

        self::assertResponseRedirects('/blog/open-comment#reactions-comment-'.$comment->getId());
    }

    public function testNotificationLinkOfAMissingTargetSaysItIsGone(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());

        $client->request(Request::METHOD_GET, '/reactions/open/post/999999');

        self::assertResponseRedirects('/notifications');
        $client->followRedirect();
        self::assertSelectorTextContains('.messages', 'no longer available');
    }

    public function testNotificationLinkNeedsASignedInUser(): void
    {
        $client = self::createClient();
        $post = PostFactory::createOne();

        $client->request(Request::METHOD_GET, '/reactions/open/post/'.$post->getId());

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testTimelineRendersActivityPlaceholders(): void
    {
        $client = self::createClient();
        $viewer = UserFactory::createOne();
        $activity = $this->activity(UserFactory::createOne());
        $client->loginUser($viewer);

        $crawler = $client->request(Request::METHOD_GET, '/activity');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#reactions-activity-'.$activity.'[data-controller="reactions-loader"]'));

        $bar = $this->bar($this->load($client, 'activity:'.$activity), 'activity', $activity);
        self::assertCount(1, $bar->filter('form'));
    }

    private function load(KernelBrowser $client, string $targets): Crawler
    {
        $crawler = $client->request(Request::METHOD_GET, '/reactions?targets='.rawurlencode($targets), [], [], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', self::STREAM.'; charset=UTF-8');

        return $crawler;
    }

    private function bar(Crawler $streams, string $type, int $id): Crawler
    {
        $stream = $streams->filter(\sprintf('turbo-stream[action="replace"][targets="#reactions-%s-%d"]', $type, $id));
        self::assertCount(1, $stream);

        $template = new Crawler(null, 'http://localhost/');
        $template->addHtmlContent('<html><body>'.$stream->filter('template')->html().'</body></html>');
        $bar = $template->filter('#reactions-'.$type.'-'.$id);
        self::assertCount(1, $bar);

        return $bar;
    }

    /**
     * @return array<string, string> count by reaction type
     */
    private function chips(Crawler $bar): array
    {
        $chips = [];
        $bar->filter('[data-testid="reaction-chip"]')->each(static function (Crawler $chip) use (&$chips): void {
            $chips[(string) $chip->attr('data-reaction-type')] = trim($chip->filter('.reaction-chip__count')->text());
        });

        return $chips;
    }

    private function token(KernelBrowser $client, string $targets, string $type, int $id): string
    {
        return (string) $this->bar($this->load($client, $targets), $type, $id)->filter('input[name="_token"]')->attr('value');
    }

    private function react(User $user, ReactionTargetType $targetType, int $targetId, ReactionType $type): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Reaction($user, $targetType, $targetId, $type, new \DateTimeImmutable('2026-11-01 10:00:00')));
        $entityManager->flush();
    }

    private function activity(User $user): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $activity = new UserActivity($user, UserActivityType::JOINED, new \DateTimeImmutable('2026-11-01 10:00:00'), 'joined-'.$user->getId());
        $entityManager->persist($activity);
        $entityManager->flush();

        return (int) $activity->getId();
    }

    private function storedReactions(): int
    {
        return (int) self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM reaction');
    }
}
