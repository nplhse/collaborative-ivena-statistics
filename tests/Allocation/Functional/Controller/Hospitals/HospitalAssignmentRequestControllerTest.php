<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Domain\Enum\HospitalAssignmentRequestStatus;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Repository\HospitalAssignmentRequestRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Participants request, list and withdraw hospital assignments (#975).
 */
#[ResetDatabase]
final class HospitalAssignmentRequestControllerTest extends WebTestCase
{
    use Factories;

    public function testMyHospitalsWithoutHospitalLeadsToTheRequestForm(): void
    {
        $client = $this->participantClient();

        $crawler = $client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        self::assertSame('/hospitals/requests/new', $crawler->filter('[data-testid="my-hospitals-empty-request-assignment"]')->attr('href'));
    }

    public function testARequestForAListedHospitalIsStoredAsPending(): void
    {
        $client = $this->participantClient($user);
        $hospital = $this->hospital(['name' => 'Klinikum Süd']);

        $this->submit($client, [
            'hospital_assignment_request[hospitalName]' => 'Klinikum Süd',
            'hospital_assignment_request[hospital]' => $hospital->getPublicIdString(),
            'hospital_assignment_request[reason]' => HospitalAssignmentReason::DataSubmission->value,
            'hospital_assignment_request[comment]' => 'I upload the monthly exports.',
        ]);

        self::assertResponseRedirects('/hospitals/requests');
        $requests = $this->requests()->findForRequester($user);
        self::assertCount(1, $requests);
        self::assertSame($hospital->getId(), $requests[0]->getHospital()?->getId());
        self::assertSame(HospitalAssignmentRequestStatus::Pending, $requests[0]->getStatus());
        self::assertSame('I upload the monthly exports.', $requests[0]->getComment());

        $crawler = $client->followRedirect();
        self::assertStringContainsString('Klinikum Süd', $crawler->filter('[data-testid="hospital-assignment-request"]')->text());
    }

    public function testAHospitalThatIsNotListedIsRequestedByName(): void
    {
        $client = $this->participantClient($user);

        $this->submit($client, [
            'hospital_assignment_request[hospitalName]' => 'Kreisklinik Nirgendwo',
            'hospital_assignment_request[hospital]' => '',
            'hospital_assignment_request[reason]' => HospitalAssignmentReason::HospitalManagement->value,
        ]);

        self::assertResponseRedirects('/hospitals/requests');
        $request = $this->requests()->findForRequester($user)[0];
        self::assertNull($request->getHospital());
        self::assertSame('Kreisklinik Nirgendwo', $request->getRequestedHospitalName());
    }

    public function testAnExactNameSelectsTheHospitalWithoutJavaScript(): void
    {
        $client = $this->participantClient($user);
        $hospital = $this->hospital(['name' => 'Klinikum West']);
        $label = \sprintf('Klinikum West (%s)', $hospital->getAddress()->getCity());

        $this->submit($client, [
            'hospital_assignment_request[hospitalName]' => $label,
            'hospital_assignment_request[hospital]' => '',
            'hospital_assignment_request[reason]' => HospitalAssignmentReason::DataSubmission->value,
        ]);

        self::assertSame($hospital->getId(), $this->requests()->findForRequester($user)[0]->getHospital()?->getId());
    }

    public function testOtherNeedsAnExplanation(): void
    {
        $client = $this->participantClient($user);
        $hospital = $this->hospital();

        $this->submit($client, [
            'hospital_assignment_request[hospital]' => $hospital->getPublicIdString(),
            'hospital_assignment_request[reason]' => HospitalAssignmentReason::Other->value,
            'hospital_assignment_request[explanation]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->requests()->findForRequester($user));
    }

    public function testASecondPendingRequestForTheSameHospitalIsRefused(): void
    {
        $client = $this->participantClient($user);
        $hospital = $this->hospital();
        $this->storedRequest($user, $hospital);

        $this->submit($client, [
            'hospital_assignment_request[hospital]' => $hospital->getPublicIdString(),
            'hospital_assignment_request[hospitalName]' => 'x',
            'hospital_assignment_request[reason]' => HospitalAssignmentReason::DataSubmission->value,
        ]);

        self::assertResponseRedirects('/hospitals/requests');
        self::assertCount(1, $this->requests()->findForRequester($user));
        self::assertStringContainsString('already have a pending request', $client->followRedirect()->filter('body')->text());
    }

    public function testAHospitalTheParticipantCanAlreadySeeIsRefused(): void
    {
        $client = $this->participantClient($user);
        $grant = HospitalAccessGrantFactory::createOne(['user' => $user, 'permissions' => 1, 'hospital' => $this->hospital()]);

        $this->submit($client, [
            'hospital_assignment_request[hospital]' => $grant->getHospital()?->getPublicIdString(),
            'hospital_assignment_request[hospitalName]' => 'x',
            'hospital_assignment_request[reason]' => HospitalAssignmentReason::DataSubmission->value,
        ]);

        self::assertSame([], $this->requests()->findForRequester($user));
        self::assertStringContainsString('already have access', $client->followRedirect()->filter('body')->text());
    }

    public function testTheRequesterWithdrawsAPendingRequest(): void
    {
        $client = $this->participantClient($user);
        $request = $this->storedRequest($user, $this->hospital());

        $crawler = $client->request(Request::METHOD_GET, '/hospitals/requests');
        $client->submit($crawler->filter('[data-testid="hospital-assignment-request-withdraw"]')->form());

        self::assertResponseRedirects('/hospitals/requests');
        self::assertSame(HospitalAssignmentRequestStatus::Withdrawn, $this->reload($request)->getStatus());
    }

    public function testRequestsOfOtherUsersCannotBeWithdrawn(): void
    {
        $client = $this->participantClient();
        $request = $this->storedRequest(UserFactory::new()->asParticipant()->create(), $this->hospital());

        $client->request(Request::METHOD_POST, '/hospitals/requests/'.$request->getPublicIdString().'/withdraw', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
        self::assertTrue($this->reload($request)->isPending());
    }

    public function testWithdrawingNeedsAValidToken(): void
    {
        $client = $this->participantClient($user);
        $request = $this->storedRequest($user, $this->hospital());

        $client->request(Request::METHOD_POST, '/hospitals/requests/'.$request->getPublicIdString().'/withdraw', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($request)->isPending());
    }

    public function testUsersWithoutParticipationCannotRequest(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());

        $client->request(Request::METHOD_GET, '/hospitals/requests/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheHospitalPageOffersTheRequestOnlyWithoutAccess(): void
    {
        $client = $this->participantClient($user);
        $hospital = $this->hospital();
        $owned = HospitalFactory::createOne(['owner' => $user]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getPublicIdString());
        self::assertSame('/hospitals/requests/new?hospital='.$hospital->getPublicIdString(), $crawler->filter('[data-testid="hospital-request-assignment"]')->attr('href'));

        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital/'.$owned->getPublicIdString());
        self::assertCount(0, $crawler->filter('[data-testid="hospital-request-assignment"]'));

        $this->storedRequest($user, $hospital);
        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getPublicIdString());
        self::assertCount(1, $crawler->filter('[data-testid="hospital-assignment-request-pending"]'));
    }

    public function testTheFormPreselectsTheHospitalOfTheDetailPage(): void
    {
        $client = $this->participantClient();
        $hospital = $this->hospital(['name' => 'Klinikum Ost']);

        $crawler = $client->request(Request::METHOD_GET, '/hospitals/requests/new?hospital='.$hospital->getPublicIdString());

        self::assertSame($hospital->getPublicIdString(), $crawler->filter('#hospital_assignment_request_hospital')->attr('value'));
        self::assertStringStartsWith('Klinikum Ost', (string) $crawler->filter('#hospital_assignment_request_hospitalName')->attr('value'));
    }

    /**
     * @param-out User $user
     */
    private function participantClient(?User &$user = null): KernelBrowser
    {
        $client = self::createClient();
        $user = UserFactory::new()->asParticipant()->create();
        $client->loginUser($user);

        return $client;
    }

    /**
     * @param array<string, string|null> $values
     */
    private function submit(KernelBrowser $client, array $values): void
    {
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/requests/new');
        $form = $crawler->filter('form[name="hospital_assignment_request"]')->form();
        foreach ($values as $name => $value) {
            $form[$name]->setValue((string) $value);
        }
        $client->submit($form);
    }

    /**
     * A hospital owned by someone else; the factory would otherwise pick any existing user as owner.
     *
     * @param array<string, mixed> $attributes
     */
    private function hospital(array $attributes = []): Hospital
    {
        return HospitalFactory::createOne([...$attributes, 'owner' => UserFactory::createOne()]);
    }

    private function storedRequest(User $user, Hospital $hospital): HospitalAssignmentRequest
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // Earlier requests of the test client may have cleared the entity manager.
        $user = $entityManager->find(User::class, $user->getId()) ?? $user;
        $hospital = $entityManager->find(Hospital::class, $hospital->getId()) ?? $hospital;
        $request = new HospitalAssignmentRequest($user, $hospital, null, HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable('2026-11-21 10:00'));
        $entityManager->persist($request);
        $entityManager->flush();

        return $request;
    }

    private function reload(HospitalAssignmentRequest $request): HospitalAssignmentRequest
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(HospitalAssignmentRequest::class, $request->getId());
        self::assertInstanceOf(HospitalAssignmentRequest::class, $reloaded);

        return $reloaded;
    }

    private function requests(): HospitalAssignmentRequestRepository
    {
        return self::getContainer()->get(HospitalAssignmentRequestRepository::class);
    }
}
