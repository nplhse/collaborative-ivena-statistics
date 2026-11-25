<?php

declare(strict_types=1);

namespace App\Tests\Research\Functional\Controller;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Entity\DataRequest;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The discussion on the request page (#1635, ADR 045): who writes, who sees private comments, how replies inherit it.
 */
#[ResetDatabase]
final class DataRequestCommentControllerTest extends WebTestCase
{
    use Factories;

    public function testBoardMembersChooseTheVisibilityAndOthersOnlySeePublicComments(): void
    {
        $client = self::createClient();
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest($applicant);

        $client->loginUser($member);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-comment-submit"]')->form();
        self::assertSame('public', $form['visibility']->getValue(), 'public is preselected');
        $form['body']->setValue('Public question about the period.');
        $client->submit($form);
        self::assertResponseRedirects($this->url($request).'#comments');

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-comment-submit"]')->form();
        $form['body']->setValue('Board only: looks fine.');
        $form['visibility']->setValue('private');
        $client->submit($form);

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertCount(2, $crawler->filter('[data-testid="data-request-comment"]'));
        self::assertCount(1, $crawler->filter('[data-testid="data-request-comment-private"]'));

        $client->loginUser($applicant);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $comments = $crawler->filter('[data-testid="data-request-comments"]')->text();
        self::assertStringContainsString('Public question about the period.', $comments);
        self::assertStringNotContainsString('Board only', $comments);
        self::assertCount(0, $crawler->filter('input[name="visibility"][value="private"]'), 'the applicant writes public comments only');

        $client->loginUser(UserFactory::new()->asParticipant()->create());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertStringContainsString('Public question about the period.', $crawler->filter('[data-testid="data-request-comments"]')->text());
        self::assertSelectorNotExists('[data-testid="data-request-comment-form"]', 'other participants only read');
    }

    public function testTheApplicantRepliesToAPublicComment(): void
    {
        $client = self::createClient();
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest($applicant);
        $client->loginUser($member);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-comment-submit"]')->form();
        $form['body']->setValue('Which hospitals?');
        $client->submit($form);

        $client->loginUser($applicant);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-reply-form"]')->form();
        $form['body']->setValue('All participating ones.');
        $client->submit($form);

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertCount(2, $crawler->filter('[data-testid="data-request-comment"]'));
        self::assertStringContainsString('All participating ones.', $crawler->filter('[data-testid="data-request-comment"] [data-testid="data-request-comment"]')->text());
    }

    public function testAReplyToAPrivateCommentShowsItIsPrivate(): void
    {
        $client = self::createClient();
        $member = UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest(UserFactory::new()->asParticipant()->create());
        $client->loginUser($member);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-comment-submit"]')->form();
        $form['body']->setValue('Board only.');
        $form['visibility']->setValue('private');
        $client->submit($form);

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));

        self::assertSelectorExists('[data-testid="data-request-reply-private-hint"]');
        self::assertSame('private', $crawler->filter('[data-testid="data-request-reply-form"] input[name="visibility"]')->attr('value'));
    }

    public function testCommentsNeedTheRightAndAValidToken(): void
    {
        $client = self::createClient();
        $request = $this->submittedRequest(UserFactory::new()->asParticipant()->create());

        $client->loginUser(UserFactory::new()->asParticipant()->create());
        $client->request(Request::METHOD_POST, $this->url($request).'/comments', ['body' => 'Hi', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser(UserFactory::new()->asBoardMember()->create());
        $client->request(Request::METHOD_POST, $this->url($request).'/comments', ['body' => 'Hi', '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_POST, '/requests/data/00000000-0000-4000-8000-000000000000/comments', ['body' => 'Hi', '_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
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
