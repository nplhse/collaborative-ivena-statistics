<?php

declare(strict_types=1);

namespace App\Tests\Research\Functional\Controller;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestQueryService;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\QueryKind;
use App\Research\Domain\Enum\VoteChoice;
use App\Tests\Research\Integration\Export\ExportFixtureTrait;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Release, export and download on the request page (#1635, ADR 045).
 */
#[ResetDatabase]
final class DataRequestReleaseControllerTest extends WebTestCase
{
    use ExportFixtureTrait;
    use Factories;

    private const string TOTAL = 'SELECT count(*) AS allocations, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a';

    public function testAnAdministratorReleasesAndTheApplicantDownloadsTheExport(): void
    {
        $client = self::createClient();
        $this->seedHospitals();
        $applicant = UserFactory::new()->asParticipant()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approvedWithQuery($applicant, $admin);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        $client->submit($crawler->filter('[data-testid="data-request-release-submit"]')->form());
        self::assertResponseRedirects($this->url($request).'#release');
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorNotExists('[data-testid="data-request-release-form"]', 'one open release at a time');
        self::assertSelectorNotExists('[data-testid="data-request-export-start"]', 'only the recipient starts the export');

        $client->loginUser($applicant);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorTextContains('[data-testid="data-request-event"][data-event="released"]', 'release 1');
        $client->submit($crawler->filter('[data-testid="data-request-export-start"]')->form());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorExists('[data-testid="data-request-export-status"][data-status="available"]');
        self::assertSelectorNotExists('[data-testid="data-request-export-record"]', 'the internal record is for administrators');

        $client->request(Request::METHOD_GET, (string) $crawler->filter('[data-testid="data-request-export-download"]')->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/gzip');
        self::assertStringContainsString('data-request-'.$request->getId().'-release-1.tar.gz', (string) $client->getResponse()->headers->get('Content-Disposition'));

        $client->loginUser(UserFactory::new()->asBoardMember()->create());
        $client->request(Request::METHOD_GET, $this->url($request).'/releases/1/download');
        self::assertResponseStatusCodeSame(403, 'the Board sees the release but does not download');

        $client->loginUser(UserFactory::new()->asParticipant()->create());
        $client->request(Request::METHOD_GET, $this->url($request).'/releases/1/download');
        self::assertResponseStatusCodeSame(404, 'other participants do not see the release');

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, $this->url($request));
        self::assertSelectorTextContains('[data-testid="data-request-export-file"][data-file="total.csv"]', 'total.csv 1 5 5');
        self::assertSelectorExists('[data-testid="data-request-export-downloads"]');
    }

    public function testReleasingAndStartingNeedTheRightAndAValidToken(): void
    {
        $client = self::createClient();
        $applicant = UserFactory::new()->asParticipant()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approvedWithQuery($applicant, $admin);

        $client->loginUser($applicant);
        $client->request(Request::METHOD_POST, $this->url($request).'/releases', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($admin);
        $client->request(Request::METHOD_POST, $this->url($request).'/releases', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        $client->request(Request::METHOD_POST, $this->url($request).'/releases/1/start', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404, 'no such release');
    }

    private function approvedWithQuery(User $applicant, User $admin): DataRequest
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
        $member = UserFactory::new()->asBoardMember()->create();
        $review = self::getContainer()->get(DataRequestReviewService::class);
        $review->openRound($member, $request);
        $review->castVote($member, $request, VoteChoice::Approve, null);
        self::getContainer()->get(DataRequestQueryService::class)->add($admin, $request, 'total', 'All allocations.', QueryKind::Aggregated, self::TOTAL);

        return $request;
    }

    private function url(DataRequest $request): string
    {
        return '/requests/data/'.$request->getPublicIdString();
    }
}
