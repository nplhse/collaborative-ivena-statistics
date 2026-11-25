<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Domain\Enum\HospitalAssignmentRequestStatus;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Administrators review hospital assignment requests (#975) through the detail page and its forms.
 */
#[ResetDatabase]
final class HospitalAssignmentRequestCrudControllerTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private User $requester;
    private User $owner;
    private Hospital $hospital;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requester = UserFactory::new()->asParticipant()->create(['username' => 'requester-'.bin2hex(random_bytes(3))]);
        $this->owner = UserFactory::new()->asParticipant()->create(['username' => 'owner-'.bin2hex(random_bytes(3))]);
        $this->hospital = HospitalFactory::createOne(['owner' => $this->owner, 'name' => 'Klinikum Mitte']);
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());
    }

    public function testTheListShowsPendingRequestsAndTheMenuCountsThem(): void
    {
        $this->request();

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/hospital-assignment-request');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Klinikum Mitte', $crawler->filter('table')->text());
        self::assertStringContainsString('1', $crawler->filter('a[href*="hospital-assignment-request"] .badge')->first()->text());
    }

    public function testParticipantsCannotOpenTheReview(): void
    {
        $this->client->loginUser($this->requester);

        $this->client->request(Request::METHOD_GET, '/admin/hospital-assignment-request');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheDetailShowsWhetherTheRequesterConfirmedTheUploadAuthority(): void
    {
        $without = $this->detail($this->request());
        self::assertStringContainsString('Not confirmed in the request', $without->filter('[data-testid="assignment-request-upload-authority"]')->text());

        $request = $this->request(HospitalFactory::createOne(['name' => 'Klinikum Ost']));
        $request->confirmUploadAuthority(new \DateTimeImmutable('2026-11-21 10:00'), null);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $with = $this->detail($request);
        self::assertStringContainsString('Confirmed in the request on 21.11.2026 10:00', $with->filter('[data-testid="assignment-request-upload-authority"]')->text());
    }

    public function testTheDetailShowsTheOwnerTheTransferWarningAndThePresetPermissions(): void
    {
        $crawler = $this->detail($this->request());

        self::assertStringContainsString($this->owner->getUsername() ?? '', $crawler->filter('[data-testid="assignment-request-owner"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="assignment-request-transfer-warning"]'));
        $checked = $crawler->filter('form[name="assignment_grant"] input[type="checkbox"]:checked')->each(static fn (Crawler $box): string => (string) $box->attr('value'));
        self::assertEqualsCanonicalizing(['1', '2', '4'], $checked, 'data submission: VIEW, STATISTICS, IMPORT');
    }

    public function testApprovingAsAccessCreatesTheGrant(): void
    {
        $request = $this->request();
        $form = $this->detail($request)->filter('form[name="assignment_grant"]')->form();

        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(7, $this->grantOf($this->requester)?->getPermissions());
        self::assertSame(HospitalAssignmentRequestStatus::Approved, $this->reload($request)->getStatus());
    }

    public function testATransferWithoutConfirmationChangesNothing(): void
    {
        $request = $this->request();
        $form = $this->detail($request)->filter('form[name="assignment_ownership"]')->form();
        $this->choice($form, 'assignment_ownership[previousOwnerAccess]')->select('keep_full_access');

        $this->client->submit($form);

        self::assertTrue($this->reload($request)->isPending());
        self::assertSame($this->owner->getId(), $this->ownerId());
    }

    public function testAConfirmedTransferMakesTheRequesterOwnerAndKeepsThePreviousOwnersAccess(): void
    {
        $request = $this->request();
        $form = $this->detail($request)->filter('form[name="assignment_ownership"]')->form();
        $this->choice($form, 'assignment_ownership[previousOwnerAccess]')->select('keep_full_access');
        $this->choice($form, 'assignment_ownership[confirmTransfer]')->tick();

        $this->client->submit($form);

        self::assertSame($this->requester->getId(), $this->ownerId());
        self::assertSame(31, $this->grantOf($this->owner)?->getPermissions());
    }

    public function testARejectionKeepsTheMessageForTheRequester(): void
    {
        $request = $this->request();
        $form = $this->detail($request)->filter('form[name="assignment_reject"]')->form();
        $form['assignment_reject[message]'] = 'Please ask your ED lead.';

        $this->client->submit($form);

        $request = $this->reload($request);
        self::assertSame(HospitalAssignmentRequestStatus::Rejected, $request->getStatus());
        self::assertSame('Please ask your ED lead.', $request->getDecisionMessage());
    }

    public function testARequestByNameIsMatchedFirst(): void
    {
        $request = $this->request(null, 'Klinikum Mite');
        $crawler = $this->detail($request);
        self::assertCount(0, $crawler->filter('form[name="assignment_grant"]'), 'no approval before the hospital is matched');

        $form = $crawler->filter('form[name="assignment_resolve"]')->form();
        $this->choice($form, 'assignment_resolve[hospital]')->select((string) $this->hospital->getId());
        $this->client->submit($form);

        self::assertSame($this->hospital->getId(), $this->reload($request)->getHospital()?->getId());
        self::assertCount(1, $this->detail($request)->filter('form[name="assignment_grant"]'));
    }

    public function testADecidedRequestShowsTheDecisionInsteadOfForms(): void
    {
        $request = $this->request();
        $this->client->submit($this->detail($request)->filter('form[name="assignment_reject"]')->form());

        $crawler = $this->detail($request);

        self::assertCount(0, $crawler->filter('[data-testid="assignment-request-decisions"]'));
        self::assertStringContainsString('Rejected', $crawler->filter('[data-testid="assignment-request-decision"]')->text());
    }

    private function request(?Hospital $hospital = null, ?string $name = null): HospitalAssignmentRequest
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $requester = $entityManager->find(User::class, $this->requester->getId());
        self::assertInstanceOf(User::class, $requester);
        $hospital = null === $name ? $entityManager->find(Hospital::class, ($hospital ?? $this->hospital)->getId()) : null;
        $request = new HospitalAssignmentRequest($requester, $hospital, $name, HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable('2026-11-21 10:00'));
        $entityManager->persist($request);
        $entityManager->flush();

        return $request;
    }

    private function choice(Form $form, string $name): ChoiceFormField
    {
        $field = $form->get($name);
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function detail(HospitalAssignmentRequest $request): Crawler
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/hospital-assignment-request/'.$request->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function reload(HospitalAssignmentRequest $request): HospitalAssignmentRequest
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(HospitalAssignmentRequest::class, $request->getId());
        self::assertInstanceOf(HospitalAssignmentRequest::class, $reloaded);

        return $reloaded;
    }

    private function grantOf(User $user): ?HospitalAccessGrant
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(HospitalAccessGrant::class)->findOneBy(['user' => $user->getId(), 'hospital' => $this->hospital->getId()]);
    }

    private function ownerId(): ?int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Hospital::class, $this->hospital->getId())?->getOwner()?->getId();
    }
}
