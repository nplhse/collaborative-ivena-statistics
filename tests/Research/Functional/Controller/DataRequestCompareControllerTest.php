<?php

declare(strict_types=1);

namespace App\Tests\Research\Functional\Controller;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestReviewService;
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
 * Comparing versions of a data extract request on its own page (#1635, ADR 045).
 */
#[ResetDatabase]
final class DataRequestCompareControllerTest extends WebTestCase
{
    use Factories;

    public function testParticipantsCompareTheFieldsTheyCanSee(): void
    {
        $client = self::createClient();
        $applicant = UserFactory::new()->asParticipant()->create();
        $request = $this->revisedRequest($applicant, submitRevision: true);

        $client->loginUser(UserFactory::new()->asParticipant()->create());
        $crawler = $client->request(Request::METHOD_GET, $this->url($request).'/compare');

        self::assertResponseIsSuccessful();
        self::assertSame(['variables'], $this->fields($crawler), 'the changed recipients stay hidden');
        self::assertCount(1, $crawler->filter('[data-field="variables"] [data-line="added"]'));

        $client->loginUser($applicant);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request).'/compare');
        self::assertSame(['variables', 'recipients'], $this->fields($crawler));
    }

    public function testAVersionThatIsNotSubmittedOnlyTheApplicantCompares(): void
    {
        $client = self::createClient();
        $applicant = UserFactory::new()->asParticipant()->create();
        $request = $this->revisedRequest($applicant, submitRevision: false);

        $client->loginUser(UserFactory::new()->asBoardMember()->create());
        $client->request(Request::METHOD_GET, $this->url($request).'/compare');
        self::assertSelectorExists('[data-testid="data-request-compare-single"]', 'the Board sees only the submitted version');

        $client->loginUser($applicant);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request).'/compare?from=1&to=2');
        self::assertSame(['variables', 'recipients'], $this->fields($crawler));
    }

    public function testTheTimelineAndTheBasicsLinkToTheComparison(): void
    {
        $client = self::createClient();
        $applicant = UserFactory::new()->asParticipant()->create();
        $request = $this->revisedRequest($applicant, submitRevision: true);

        $client->loginUser($applicant);
        $crawler = $client->request(Request::METHOD_GET, $this->url($request));

        self::assertStringEndsWith('/compare', (string) $crawler->filter('[data-testid="data-request-compare-link"]')->attr('href'));
        self::assertStringEndsWith('/compare?from=1&to=2', (string) $crawler->filter('[data-testid="data-request-event-compare"]')->attr('href'));
    }

    /**
     * A request returned by the formal check, with a second version that changes variables and recipients.
     */
    private function revisedRequest(User $applicant, bool $submitRevision): DataRequest
    {
        $service = self::getContainer()->get(DataRequestService::class);
        $request = $service->createDraft($applicant, $this->draft("Month\nPZC", 'Team A'));
        $service->submit($applicant, $request);
        self::getContainer()->get(DataRequestReviewService::class)->returnRequest(UserFactory::new()->asAdmin()->create(), $request, 'Please name the age groups.');
        $service->updateDraft($applicant, $request, $this->draft("Month\nPZC\nAge group", 'Team B'));
        if ($submitRevision) {
            $service->submit($applicant, $request);
        }

        return $request;
    }

    private function draft(string $variables, string $recipients): DataRequestDraft
    {
        $draft = new DataRequestDraft();
        $draft->title = 'Stroke pathways';
        $draft->summary = 'Summary';
        $draft->institution = 'University';
        $draft->purpose = 'Purpose';
        $draft->periodFrom = new \DateTimeImmutable('2024-01-01');
        $draft->periodTo = new \DateTimeImmutable('2024-12-31');
        $draft->population = 'All allocations';
        $draft->variables = $variables;
        $draft->granularity = 'Month';
        $draft->recipients = $recipients;
        $draft->description = 'Description';
        $draft->dataNeeds = 'Data needs';
        $draft->responsiblePersons = 'Dr. A';
        $draft->conflictsOfInterest = 'none';
        $draft->ethics = 'Not needed';

        return $draft;
    }

    /**
     * @return list<string>
     */
    private function fields(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid="data-request-change"]')->each(static fn (Crawler $card): string => (string) $card->attr('data-field'));
    }

    private function url(DataRequest $request): string
    {
        return '/requests/data/'.$request->getPublicIdString();
    }
}
