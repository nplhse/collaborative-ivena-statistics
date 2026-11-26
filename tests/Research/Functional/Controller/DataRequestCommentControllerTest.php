<?php

declare(strict_types=1);

namespace App\Tests\Research\Functional\Controller;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Entity\DataRequest;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The timeline on the request page (#1635, ADR 045): events and comments in order, the visibility levels and replies.
 */
#[ResetDatabase]
final class DataRequestCommentControllerTest extends WebTestCase
{
    use Factories;

    public function testEachReaderSeesTheLevelsTheyMayRead(): void
    {
        $client = self::createClient();
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest($applicant);

        $client->loginUser($member);
        foreach (['public' => 'Public question.', 'private' => 'Question to the applicant.', 'internal' => 'Board only.'] as $level => $body) {
            $crawler = $client->request(Request::METHOD_GET, $this->url($request));
            $form = $crawler->filter('[data-testid="data-request-comment-submit"]')->form();
            $form['body']->setValue($body);
            $form['visibility']->setValue($level);
            $client->submit($form);
            self::assertResponseRedirects($this->url($request).'#comments');
        }

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSame(['public', 'private', 'internal'], $this->levels($crawler));

        $client->loginUser($applicant);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSame(['public', 'private'], $this->levels($crawler));
        self::assertSame(['public', 'private'], $crawler->filter('[data-testid="data-request-comment-form"] input[name="visibility"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));

        $client->loginUser(UserFactory::new()->asParticipant()->create());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSame(['public'], $this->levels($crawler));
        self::assertSelectorNotExists('[data-testid="data-request-comment-form"]', 'other participants only read');
        self::assertSelectorExists('[data-testid="data-request-event"][data-event="submitted"]');

        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSame(['public', 'private', 'internal'], $this->levels($crawler), 'administrators see everything');
    }

    public function testAReplyOffersOnlyLevelsUpToItsComment(): void
    {
        $client = self::createClient();
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest($applicant);
        $client->loginUser($member);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-comment-submit"]')->form();
        $form['body']->setValue('Which hospitals?');
        $form['visibility']->setValue('private');
        $client->submit($form);

        $client->loginUser($applicant);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $reply = $crawler->filter('[data-testid="data-request-reply-form"]');
        self::assertSame('private', $reply->filter('input[name="visibility"]')->attr('value'), 'only private is left for the applicant');
        $form = $reply->form();
        $form['body']->setValue('All participating ones.');
        $client->submit($form);

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertStringContainsString('All participating ones.', $crawler->filter('[data-testid="data-request-comment"] [data-testid="data-request-comment"]')->text());
    }

    public function testCommentsNeedTheRightAndAValidToken(): void
    {
        $client = self::createClient();
        $request = $this->submittedRequest(UserFactory::new()->asParticipant()->create());

        $client->loginUser(UserFactory::new()->asParticipant()->create());
        $client->request(Request::METHOD_POST, $this->url($request).'/comments', ['body' => 'Hi', 'visibility' => 'public', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser(UserFactory::new()->asBoardMember()->create());
        $client->request(Request::METHOD_POST, $this->url($request).'/comments', ['body' => 'Hi', 'visibility' => 'public', '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_POST, '/requests/data/00000000-0000-4000-8000-000000000000/comments', ['body' => 'Hi', '_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<string>
     */
    private function levels(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid="data-request-comment"] [data-testid="data-request-visibility"]')->each(static fn (Crawler $badge): string => (string) $badge->attr('data-visibility'));
    }

    private function submittedRequest(User $applicant): DataRequest
    {
        $draft = new DataRequestDraft();
        $draft->title = 'Stroke pathways';
        $draft->summary = 'Summary';
        $draft->institution = 'University';
        $draft->purpose = 'Purpose';
        $draft->periodFrom = new \DateTimeImmutable('2024-01-01');
        $draft->periodTo = new \DateTimeImmutable('2024-12-31');
        $draft->population = 'All allocations';
        $draft->variables = 'Month';
        $draft->granularity = 'Month';
        $draft->recipients = 'Team';
        $draft->description = 'Description';
        $draft->dataNeeds = 'Data needs';
        $draft->responsiblePersons = 'Dr. A';
        $draft->conflictsOfInterest = 'none';
        $draft->ethics = 'Not needed';

        $service = self::getContainer()->get(DataRequestService::class);
        $request = $service->createDraft($applicant, $draft);
        $service->submit($applicant, $request);

        return $request;
    }

    private function url(DataRequest $request): string
    {
        return '/requests/data/'.$request->getPublicIdString();
    }
}
