<?php

declare(strict_types=1);

namespace App\Tests\Research\Functional\Controller;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Participants draft, submit and withdraw data extract requests, and see what others may see (#1635, ADR 045).
 */
#[ResetDatabase]
final class DataRequestControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const array VALUES = [
        'title' => 'Stroke pathways in 2024',
        'summary' => 'Which hospitals receive stroke patients, by month.',
        'institution' => 'University of Example',
        'purpose' => 'Compare stroke allocations between hospital groups.',
        'periodFrom' => '2024-01-01',
        'periodTo' => '2024-12-31',
        'population' => 'Allocations with a stroke PZC',
        'variables' => 'Month, hospital group, PZC',
        'granularity' => 'Month and hospital group',
        'recipients' => 'Dr. Secret Recipient',
        'description' => 'Background and method.',
        'dataNeeds' => 'The PZC identifies stroke cases.',
        'responsiblePersons' => 'Prof. Responsible',
        'conflictsOfInterest' => 'none',
        'ethics' => 'Ethics vote 2024-17',
    ];

    public function testAParticipantSavesADraftThatOnlyTheySee(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-25 10:00:00'));
        $client = $this->clientFor($applicant);

        $this->saveDraft($client);

        $request = $this->onlyRequest();
        self::assertResponseRedirects('/requests/data/'.$request->getPublicIdString());
        self::assertSame(DataRequestStatus::Draft, $request->getStatus());
        self::assertSame('Stroke pathways in 2024', $request->getCurrentVersion()->getTitle());
        self::assertTrue($request->isRequestedBy($applicant));

        $crawler = $client->followRedirect();
        self::assertSelectorExists('[data-testid="data-request-draft-note"]');
        self::assertSelectorExists('[data-testid="data-request-submit"]');
        self::assertStringContainsString('Dr. Secret Recipient', $crawler->filter('[data-testid="data-request-scope"]')->text());

        $other = $this->clientFor($otherUser, $client);
        $other->request(Request::METHOD_GET, '/requests/data/'.$request->getPublicIdString());
        self::assertResponseStatusCodeSame(404);
        $other->request(Request::METHOD_GET, '/requests/data');
        self::assertSelectorNotExists('[data-testid="data-request-row"]');
    }

    public function testEnrichmentNeedsDetails(): void
    {
        $client = $this->clientFor($applicant);

        $this->saveDraft($client, ['enrichmentPlanned' => true]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::getContainer()->get(DataRequestRepository::class)->findAll());
    }

    public function testASubmittedRequestShowsParticipantsOnlyTheVisibleFields(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-25 10:00:00'));
        $client = $this->clientFor($applicant);
        $this->saveDraft($client);
        $request = $this->onlyRequest();

        $this->transition($client, $request, 'submit');
        self::assertResponseRedirects('/requests/data/'.$request->getPublicIdString());
        $request = $this->onlyRequest();
        self::assertSame(DataRequestStatus::Submitted, $request->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-11-25 10:00:00'), $request->getSubmittedAt());
        self::assertTrue($request->getCurrentVersion()->isSubmitted());

        $client->request(Request::METHOD_GET, '/requests/data/'.$request->getPublicIdString());
        self::assertSelectorNotExists('[data-testid="data-request-edit"]', 'a submitted version cannot change');

        $participant = $this->clientFor($viewer, $client);
        $crawler = $participant->request(Request::METHOD_GET, '/requests/data');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="data-request-row"]'));

        $crawler = $participant->request(Request::METHOD_GET, '/requests/data/'.$request->getPublicIdString());
        self::assertResponseIsSuccessful();
        $page = $crawler->filter('body')->text();
        self::assertStringContainsString('Stroke pathways in 2024', $page);
        self::assertStringContainsString('Month and hospital group', $page);
        foreach (['Dr. Secret Recipient', 'Prof. Responsible', 'Ethics vote 2024-17'] as $hidden) {
            self::assertStringNotContainsString($hidden, $page);
        }
        self::assertGreaterThan(0, $crawler->filter('[data-testid="data-request-hidden-field"]')->count());
        self::assertSelectorNotExists('[data-testid="data-request-withdraw"]');
    }

    public function testBoardMembersSeeTheInternalDetailsOfSubmittedRequests(): void
    {
        $client = $this->clientFor($applicant);
        $this->saveDraft($client);
        $request = $this->onlyRequest();

        $board = $this->createClientForUser(UserFactory::new()->asBoardMember()->create(), $client);
        $board->request(Request::METHOD_GET, '/requests/data/'.$request->getPublicIdString());
        self::assertResponseStatusCodeSame(404, 'drafts stay with the applicant');

        $this->clientFor($applicant, $client, $applicant);
        $this->transition($client, $request, 'submit');

        $board = $this->createClientForUser(UserFactory::new()->asBoardMember()->create(), $client);
        $crawler = $board->request(Request::METHOD_GET, '/requests/data/'.$request->getPublicIdString());
        self::assertStringContainsString('Prof. Responsible', $crawler->filter('[data-testid="data-request-internal"]')->text());
        self::assertStringContainsString('Dr. Secret Recipient', $crawler->filter('[data-testid="data-request-scope"]')->text());
    }

    public function testTheApplicantWithdrawsARequest(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-25 10:00:00'));
        $client = $this->clientFor($applicant);
        $this->saveDraft($client);
        $request = $this->onlyRequest();
        $this->transition($client, $request, 'submit');

        self::mockTime(new \DateTimeImmutable('2026-11-26 09:00:00'));
        $this->transition($client, $request, 'withdraw');

        $request = $this->onlyRequest();
        self::assertSame(DataRequestStatus::Withdrawn, $request->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-11-26 09:00:00'), $request->getClosedAt());

        $client->request(Request::METHOD_GET, '/requests/data/'.$request->getPublicIdString());
        self::assertSelectorNotExists('[data-testid="data-request-withdraw"]', 'a withdrawn request takes no further step');
        self::assertSelectorNotExists('[data-testid="data-request-submit"]');
    }

    public function testOthersCannotSubmitOrEditARequest(): void
    {
        $client = $this->clientFor($applicant);
        $this->saveDraft($client);
        $request = $this->onlyRequest();

        $other = $this->clientFor($otherUser, $client);
        $other->request(Request::METHOD_POST, '/requests/data/'.$request->getPublicIdString().'/submit', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $other->request(Request::METHOD_GET, '/requests/data/'.$request->getPublicIdString().'/edit');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(DataRequestStatus::Draft, $this->onlyRequest()->getStatus());
    }

    public function testUsersWithoutTheParticipantRoleCannotOpenTheList(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->create());

        $client->request(Request::METHOD_GET, '/requests/data');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMyRequestsListsBothKindsOfRequests(): void
    {
        $client = $this->clientFor($applicant);
        $this->saveDraft($client);

        $crawler = $client->request(Request::METHOD_GET, '/requests');

        self::assertResponseIsSuccessful();
        $sections = $crawler->filter('[data-testid="my-requests-section"]')->each(static fn (Crawler $node): string => (string) $node->attr('data-section'));
        self::assertSame(['hospital_assignment', 'data_request'], $sections);
        self::assertStringContainsString('Stroke pathways in 2024', $crawler->filter('[data-section="data_request"]')->text());
        self::assertCount(1, $crawler->filter('[data-section="hospital_assignment"] [data-testid="my-requests-empty"]'));
    }

    /**
     * @param array<string, string|bool> $overrides
     */
    private function saveDraft(KernelBrowser $client, array $overrides = []): void
    {
        $crawler = $client->request(Request::METHOD_GET, '/requests/data/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-testid="data-request-save"]')->form();
        foreach ([...self::VALUES, ...$overrides] as $field => $value) {
            $name = 'data_request['.$field.']';
            if (\is_bool($value)) {
                $checkbox = $form[$name];
                self::assertInstanceOf(ChoiceFormField::class, $checkbox);
                $value ? $checkbox->tick() : $checkbox->untick();
            } else {
                $form[$name]->setValue($value);
            }
        }
        $client->submit($form);
    }

    private function transition(KernelBrowser $client, DataRequest $request, string $transition): void
    {
        $crawler = $client->request(Request::METHOD_GET, '/requests/data/'.$request->getPublicIdString());
        $client->submit($crawler->filter('[data-testid="data-request-'.$transition.'"]')->form());
    }

    private function onlyRequest(): DataRequest
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $requests = self::getContainer()->get(DataRequestRepository::class)->findAll();
        self::assertCount(1, $requests);

        return $requests[0];
    }

    /**
     * Logs in a new participant, or $user when given, on $client (or a new client).
     *
     * @param-out User $user
     */
    private function clientFor(?User &$user, ?KernelBrowser $client = null, ?User $existing = null): KernelBrowser
    {
        $client ??= self::createClient();
        $user = $existing ?? UserFactory::new()->asParticipant()->create();
        $client->loginUser($user);

        return $client;
    }

    private function createClientForUser(User $user, KernelBrowser $client): KernelBrowser
    {
        $client->loginUser($user);

        return $client;
    }
}
