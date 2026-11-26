<?php

declare(strict_types=1);

namespace App\Tests\Research\Functional\Controller;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\VoteChoice;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Administrators write export queries on an approved request; everyone who may see the request reads the SQL
 * (#1635, ADR 045).
 */
#[ResetDatabase]
final class DataRequestQueryControllerTest extends WebTestCase
{
    use Factories;

    private const string SQL = 'SELECT h.location, count(*) AS allocations, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a JOIN hospital h ON h.id = a.hospital_id GROUP BY h.location';

    public function testAnAdministratorAddsACheckedQueryThatParticipantsRead(): void
    {
        $client = self::createClient();
        $request = $this->approvedRequest();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-query-add"]')->form();
        $form['name']->setValue('allocations_by_location');
        $form['description']->setValue('Allocations per hospital location.');
        $form['sql']->setValue(self::SQL);
        $client->submit($form);
        self::assertResponseRedirects($this->url($request).'#queries');

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorTextContains('[data-query="allocations_by_location"] [data-testid="data-request-query-check"]', 'Allowlist check passed.');
        self::assertSelectorTextContains('[data-testid="data-request-event"][data-event="query_saved"]', 'allocations_by_location');

        $client->submit($crawler->filter('[data-testid="data-request-query-dry-run"]')->form());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorTextContains('[data-testid="data-request-dry-run"]', '0 rows from 0 hospitals');

        $client->loginUser(UserFactory::new()->asParticipant()->create());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertStringContainsString('_hospital_ids', $crawler->filter('[data-testid="data-request-query-sql"]')->text());
        self::assertSelectorNotExists('[data-testid="data-request-query-check"]', 'check results are for administrators');
        self::assertSelectorNotExists('[data-testid="data-request-query-add-form"]');
    }

    public function testARefusedQueryShowsWhyAndCannotRun(): void
    {
        $client = self::createClient();
        $request = $this->approvedRequest();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $form = $crawler->filter('[data-testid="data-request-query-add"]')->form();
        $form['name']->setValue('names');
        $form['description']->setValue('Hospital names.');
        $form['sql']->setValue('SELECT h.name, array_agg(DISTINCT h.id) AS _hospital_ids FROM hospital h GROUP BY h.name');
        $client->submit($form);

        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorTextContains('[data-testid="data-request-query-check"]', 'The column hospital.name is blocked.');
        self::assertNotNull($crawler->filter('[data-testid="data-request-query-dry-run"]')->attr('disabled'));
    }

    public function testOnlyAdministratorsManageQueriesAndOnlyOnApprovedRequests(): void
    {
        $client = self::createClient();
        $request = $this->approvedRequest();

        $client->loginUser(UserFactory::new()->asBoardMember()->create());
        $client->request(Request::METHOD_POST, $this->url($request).'/queries/add', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $submitted = $this->submittedRequest(UserFactory::new()->asParticipant()->create());
        $client->loginUser(UserFactory::new()->asAdmin()->create());
        $client->request(Request::METHOD_GET, $this->url($submitted));
        self::assertSelectorNotExists('[data-testid="data-request-queries"]', 'no queries before the approval');
        $client->request(Request::METHOD_POST, $this->url($submitted).'/queries/add', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    private function approvedRequest(): DataRequest
    {
        $request = $this->submittedRequest(UserFactory::new()->asParticipant()->create());
        $member = UserFactory::new()->asBoardMember()->create();
        $review = self::getContainer()->get(DataRequestReviewService::class);
        $review->openRound($member, $request);
        $review->castVote($member, $request, VoteChoice::Approve, null);

        $reloaded = self::getContainer()->get(DataRequestRepository::class)->findOneByPublicId($request->getPublicIdString());
        self::assertInstanceOf(DataRequest::class, $reloaded);
        self::assertSame('approved', $reloaded->getStatus()->value);

        return $reloaded;
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
