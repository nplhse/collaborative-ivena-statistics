<?php

declare(strict_types=1);

namespace App\Tests\Research\Functional\Controller;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Enum\VoteChoice;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The formal check and the vote on the request page (#1635, ADR 045): who sees which action and which votes.
 */
#[ResetDatabase]
final class DataRequestReviewControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testABoardMemberOpensTheVoteAndVotes(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-12-01 09:00:00'));
        $client = self::createClient();
        [$member, $other] = [UserFactory::new()->asBoardMember()->create(), UserFactory::new()->asBoardMember()->create()];
        $request = $this->submittedRequest();

        $client->loginUser($member);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorExists('[data-testid="data-request-check"]');
        $client->submit($crawler->filter('[data-testid="data-request-open-round"]')->form());
        self::assertResponseRedirects($this->url($request));
        self::assertSame(DataRequestStatus::InReview, $this->reload($request)->getStatus());

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertStringContainsString('0 of 2 votes cast, at least 2 needed', $crawler->filter('[data-testid="data-request-round"]')->text());
        $form = $crawler->filter('[data-testid="data-request-vote"]')->form();
        $form['choice']->setValue(VoteChoice::Revision->value);
        $form['message']->setValue('Coarser age groups, please.');
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-testid="data-request-votes"]', 'Coarser age groups, please.');
        self::assertSelectorTextContains('[data-testid="data-request-vote"]', 'Change vote');

        $client->loginUser($other);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-vote"]')->form();
        $form['choice']->setValue(VoteChoice::Revision->value);
        $client->submit($form);

        // NN: 2 of 2 needs revision → revision requested, two weeks to revise.
        self::assertSame(DataRequestStatus::RevisionRequested, $this->reload($request)->getStatus());
    }

    public function testParticipantsSeeTheRoundButNoSingleVotes(): void
    {
        $client = self::createClient();
        $member = UserFactory::new()->asBoardMember()->create();
        UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest();
        $client->loginUser($member);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $client->submit($crawler->filter('[data-testid="data-request-open-round"]')->form());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-vote"]')->form();
        $form['choice']->setValue(VoteChoice::Approve->value);
        $form['message']->setValue('Secret board reasoning');
        $client->submit($form);

        $client->loginUser(UserFactory::new()->asParticipant()->create());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 of 2 votes cast', $crawler->filter('[data-testid="data-request-round"]')->text());
        self::assertSelectorNotExists('[data-testid="data-request-votes"]');
        self::assertSelectorNotExists('[data-testid="data-request-vote-form"]');
        self::assertSelectorNotExists('[data-testid="data-request-check"]');
        self::assertStringNotContainsString('Secret board reasoning', $crawler->filter('body')->text());
    }

    public function testTheApplicantCannotCheckOrVoteOnTheirOwnRequest(): void
    {
        $client = self::createClient();
        UserFactory::new()->asBoardMember()->create();
        $applicant = UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest($applicant);

        $client->loginUser($applicant);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorNotExists('[data-testid="data-request-check"]');
        self::assertSelectorNotExists('[data-testid="data-request-exclude-self-form"]');
        self::assertStringContainsString('Waiting for the formal check', $crawler->filter('[data-testid="data-request-review"]')->text());

        $client->request(Request::METHOD_POST, $this->url($request).'/review/open', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheApplicantSeesTheReturnMessageAndEditsTheNextVersion(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $applicant = UserFactory::new()->asParticipant()->create();
        $request = $this->submittedRequest($applicant);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-return"]')->form();
        $form['message']->setValue('Please name the variables.');
        $client->submit($form);
        self::assertSame(DataRequestStatus::Returned, $this->reload($request)->getStatus());

        $client->loginUser($applicant);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorTextContains('[data-testid="data-request-handback"]', 'Please name the variables.');
        self::assertSelectorExists('[data-testid="data-request-edit"]');
        self::assertSelectorNotExists('[data-testid="data-request-submit"]', 'the next version needs a change first');

        $crawler = $client->request(Request::METHOD_GET, $this->url($request).'/edit');
        $form = $crawler->filter('[data-testid="data-request-save"]')->form();
        $form['data_request[variables]']->setValue('Month, PZC, age group');
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-testid="data-request-version"]', '2 of 2');
        self::assertSelectorExists('[data-testid="data-request-submit"]');
    }

    public function testAnAdministratorResolvesAVeto(): void
    {
        $client = self::createClient();
        $member = UserFactory::new()->asBoardMember()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->submittedRequest();

        $client->loginUser($member);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $client->submit($crawler->filter('[data-testid="data-request-open-round"]')->form());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-vote"]')->form();
        $form['choice']->setValue(VoteChoice::Veto->value);
        $form['message']->setValue('The ethics vote is missing.');
        $client->submit($form);
        self::assertSame(DataRequestStatus::Stalled, $this->reload($request)->getStatus());

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorTextContains('[data-testid="data-request-votes"]', 'The ethics vote is missing.');
        $form = $crawler->filter('[data-testid="data-request-resolve"]')->form();
        $form['resolution']->setValue('restart');
        $client->submit($form);

        $reloaded = $this->reload($request);
        self::assertSame(DataRequestStatus::InReview, $reloaded->getStatus());
        self::assertCount(2, $reloaded->getRounds());
    }

    public function testABoardMemberDeclaresAConflictOfInterest(): void
    {
        $client = self::createClient();
        $member = UserFactory::new()->asBoardMember()->create();
        UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest();

        $client->loginUser($member);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorExists('[data-testid="data-request-exclude-self-open"]');
        self::assertSelectorNotExists('[data-testid="data-request-exclude-member-open"]', 'only administrators exclude others');
        $form = $crawler->filter('[data-testid="data-request-exclude-self"]')->form();
        self::assertSame((string) $member->getId(), $form['member']->getValue());
        $form['message']->setValue('I co-author the project.');
        $client->submit($form);
        $crawler = $client->followRedirect();

        self::assertSelectorTextContains('[data-testid="data-request-exclusions"]', 'I co-author the project.');
        self::assertSelectorNotExists('[data-testid="data-request-check"]', 'an excluded member does not do the formal check');
        self::assertTrue($this->reload($request)->isExcluded($member));
    }

    public function testAnAdministratorExcludesAnotherBoardMember(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorExists('[data-testid="data-request-exclude-member-open"]');
        $form = $crawler->filter('[data-testid="data-request-exclude-member"]')->form();
        $form['member']->setValue((string) $member->getId());
        $form['message']->setValue('Same department as the applicant.');
        $client->submit($form);

        self::assertTrue($this->reload($request)->isExcluded($member));
    }

    public function testABoardMemberCannotExcludeSomeoneElse(): void
    {
        $client = self::createClient();
        $member = UserFactory::new()->asBoardMember()->create();
        $other = UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest();

        $client->loginUser($member);
        $client->request(Request::METHOD_POST, $this->url($request).'/review/exclude', ['member' => $other->getId(), 'message' => 'x', '_token' => 'x']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($request)->isExcluded($other));
    }

    public function testTheHeaderShowsStatusApplicantAndTheIdOnlyToAdministrators(): void
    {
        $client = self::createClient();
        $applicant = UserFactory::new()->asParticipant()->create(['username' => 'stroke-researcher']);
        $request = $this->submittedRequest($applicant);

        $client->loginUser(UserFactory::new()->asParticipant()->create());
        $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorTextContains('[data-testid="data-request-meta"]', 'Submitted');
        self::assertSelectorTextContains('[data-testid="data-request-applicant"]', 'stroke-researcher');
        self::assertSelectorNotExists('[data-testid="admin-object-id"]');
        self::assertSelectorExists('[data-testid="data-request-basics"]');
        self::assertSelectorNotExists('[data-testid="data-request-actions"]', 'a participant has nothing to do here');

        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorTextContains('[data-testid="admin-object-id"]', (string) $request->getId());
        self::assertSelectorExists('[data-testid="data-request-actions"] [data-testid="data-request-check"]');
    }

    public function testReviewStepsNeedAVisibleRequestAValidTokenAndAReasonForAVeto(): void
    {
        $client = self::createClient();
        $member = UserFactory::new()->asBoardMember()->create();
        UserFactory::new()->asBoardMember()->create();
        $request = $this->submittedRequest();
        $client->loginUser($member);

        $client->request(Request::METHOD_POST, '/requests/data/00000000-0000-4000-8000-000000000000/review/open', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $client->request(Request::METHOD_POST, $this->url($request).'/review/open', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $client->submit($crawler->filter('[data-testid="data-request-open-round"]')->form());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-vote"]')->form();
        $form['choice']->setValue(VoteChoice::Veto->value);
        $client->submit($form);
        $client->followRedirect();

        self::assertSelectorTextContains('.messages', 'Please give a reason.');
        self::assertSame(DataRequestStatus::InReview, $this->reload($request)->getStatus());
    }

    private function submittedRequest(?User $applicant = null): DataRequest
    {
        $applicant ??= UserFactory::new()->asParticipant()->create();
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

    private function reload(DataRequest $request): DataRequest
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $reloaded = self::getContainer()->get(DataRequestRepository::class)->findOneByPublicId($request->getPublicIdString());
        self::assertInstanceOf(DataRequest::class, $reloaded);

        return $reloaded;
    }

    private function url(DataRequest $request): string
    {
        return '/requests/data/'.$request->getPublicIdString();
    }
}
