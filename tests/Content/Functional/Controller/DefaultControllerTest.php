<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\Onboarding\Infrastructure\Factory\UserOnboardingStepFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DefaultControllerTest extends WebTestCase
{
    use Factories;

    public function testPublicHomepageIsDisplayedForGuests(): void
    {
        $client = self::createClient();

        UserFactory::createOne(['username' => 'area-user']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        ImportFactory::createOne(['name' => 'Test Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        IndicationRawFactory::createOne(['name' => 'Test Indication']);
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
        AllocationFactory::createMany(25);

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Research Together.');
        self::assertSelectorTextContains('body', 'About us');
        self::assertSelectorTextContains('body', 'Core platform strengths');
        self::assertSelectorExists('[data-testid="home-screenshot-carousel"]');
        self::assertSelectorExists('#home-screenshots-carousel .carousel-indicators button');
        self::assertSelectorExists('#home-screenshots-carousel .carousel-control-prev');
        self::assertSelectorExists('#home-screenshots-carousel .carousel-control-next');
        self::assertSelectorExists('a[data-fslightbox="home-screenshots"][href*="/assets/images/home/dashboard"]');
        self::assertSelectorExists('a[data-fslightbox="home-screenshots"][href*="/assets/images/home/benchmarking"]');
        self::assertSelectorExists('a[data-fslightbox="home-screenshots"][href*="/assets/images/home/indication-insights"]');
        self::assertSelectorExists('a[data-fslightbox="home-screenshots"][href*="/assets/images/home/analytics"]');
        self::assertSelectorExists('.home-screenshot-carousel .carousel-caption-background');
        self::assertSelectorExists('.home-screenshot-carousel .carousel-caption[data-testid="home-screenshot-caption"]');
        self::assertSelectorTextContains('body', 'Shared overview of allocations, trends and clinical distributions across the network.');
        self::assertSelectorTextContains('body', 'Users');
        self::assertSelectorTextContains('body', 'Hospitals');
        self::assertSelectorTextContains('body', 'Allocations');
        self::assertSelectorTextContains('body', 'Imports');
        self::assertSelectorNotExists('[data-testid="footer-nav-activity"]');
    }

    public function testAuthenticatedUsersSeeDashboardOnHomepage(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'dashboard-user']);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Analyze our Data');
        self::assertSelectorTextNotContains('body', 'Analyze your Data');
        self::assertSelectorTextContains('body', 'Read the Blog');
        self::assertSelectorExists('a[href="/statistics/"]');
        self::assertSelectorExists('a[href="/blog"]');
        self::assertSelectorTextContains('body', 'Latest blog posts');
        self::assertSelectorTextContains('body', 'No published posts yet.');
        // Without visible pages the page navigation is not rendered at all.
        self::assertSelectorNotExists('[data-testid="page-navigation"]');
        self::assertSelectorExists('[data-testid="dashboard-metrics"]');
        self::assertSelectorExists('[data-testid="dashboard-metric-allocations"]');
        self::assertSelectorExists('[data-testid="dashboard-metric-hospitals"]');
        self::assertSelectorExists('[data-testid="dashboard-metric-users"]');
        self::assertSelectorExists('[data-testid="dashboard-metric-imports"]');
        self::assertSelectorTextContains('[data-testid="dashboard-metric-users"]', 'last 30 days');
        self::assertSelectorExists('turbo-frame#dashboard-activity[src="/dashboard/activity"]');
        self::assertSelectorExists('[data-testid="dashboard-activity-view-all"]');
        self::assertSelectorExists('a[href="/activity"]');
        self::assertSelectorExists('[data-testid="footer-nav-activity"]');
        self::assertSelectorNotExists('header a[href="/activity"]');
        self::assertSelectorExists('[data-testid="dashboard-participant-access-notice"]');
        self::assertSelectorTextContains('body', 'Your current access');
        self::assertSelectorTextContains('body', 'Browse and explore allocation data');
        self::assertSelectorExists('[data-testid="dashboard-participant-access-feedback"]');
    }

    public function testDashboardHidesZeroLast30DaysDeltas(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'username' => 'dashboard-zero-delta-user',
            'createdAt' => new \DateTimeImmutable('-40 days'),
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-metrics"]');
        self::assertSelectorTextNotContains('[data-testid="dashboard-metrics"]', 'last 30 days');
    }

    public function testAuthenticatedUsersWithoutParticipantDoNotSeeParticipantOnlyLinks(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'non-participant-user']);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/explore"]');
        self::assertSelectorNotExists('a[href="/explore/hospital"]');
        self::assertSelectorNotExists('a[href="/import"]');
        self::assertSelectorNotExists('a[data-testid="dashboard-metric-allocations"]');
        self::assertSelectorNotExists('a[data-testid="dashboard-metric-hospitals"]');
        self::assertSelectorNotExists('a[data-testid="dashboard-metric-users"]');
        self::assertSelectorNotExists('a[data-testid="dashboard-metric-imports"]');
        self::assertSelectorExists('div[data-testid="dashboard-metric-allocations"]');
        self::assertSelectorExists('a[href="/statistics/"]');
        self::assertSelectorTextContains('body', 'Analyze our Data');
        self::assertSelectorTextNotContains('body', 'Analyze your Data');
        self::assertSelectorExists('[data-testid="dashboard-participant-access-notice"]');

        $client->request(Request::METHOD_GET, '/statistics/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#navbar-menu a[href="/explore"]');
        self::assertSelectorNotExists('#navbar-menu a[href="/import"]');
        self::assertSelectorExists('#navbar-menu a[href="/statistics/"]');
    }

    public function testParticipantUsersSeeExploreAndImportLinks(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'username' => 'participant-nav-user',
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        // Without clinic access the first tile leads to the assignment request (#1483).
        self::assertSelectorExists('a[data-testid="dashboard-tile-access-request"][href="/hospitals/requests/new"]');
        self::assertSelectorTextContains('[data-testid="dashboard-tile-access-request"]', 'Request hospital assignment');
        self::assertSelectorTextNotContains('body', 'View all Hospitals');
        self::assertSelectorTextContains('body', 'Explore our Data');
        self::assertSelectorTextContains('body', 'Analyze your Data');
        self::assertSelectorNotExists('[data-testid="dashboard-participant-access-notice"]');
        self::assertSelectorExists('a[href="/explore"]');
        self::assertSelectorExists('a[data-testid="dashboard-metric-allocations"][href="/explore/allocation"]');
        self::assertSelectorExists('a[data-testid="dashboard-metric-hospitals"][href="/explore/hospital?participating=1"]');
        self::assertSelectorExists('a[data-testid="dashboard-metric-users"][href="/explore/user"]');
        self::assertSelectorExists('a[data-testid="dashboard-metric-imports"][href="/import"]');
        // Without an upload right the dashboard does not offer the import form (#1351).
        self::assertSelectorNotExists('a[href="/import/new"]');

        $client->request(Request::METHOD_GET, '/statistics/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#navbar-menu a[href="/explore"]');
        self::assertSelectorExists('#navbar-menu a[href="/import"]');
        self::assertSelectorExists('#navbar-menu a[href="/statistics/"]');
    }

    public function testParticipantUsersWithHospitalsSeeOwnedHospitalsAndImportActions(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'username' => 'participant-dashboard-user',
        ]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne([
            'createdBy' => $user,
            'state' => $state,
        ]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'View your Hospitals');
        self::assertSelectorTextContains('body', 'Import new Allocations');
        self::assertSelectorExists('a[data-testid="dashboard-tile-hospitals"][href="/hospitals"]');
        self::assertSelectorNotExists('[data-testid^="dashboard-tile-access-request"]');
        self::assertSelectorExists('a[href="/import/new"]');
    }

    public function testParticipantWithImportGrantButNoOwnHospitalIsOfferedTheImportForm(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'username' => 'participant-import-grant-user',
        ]);
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $user,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View, HospitalPermission::Import]),
            'createdBy' => $owner,
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Import new Allocations');
        self::assertSelectorExists('a[href="/import/new"]');
    }

    public function testParticipantWithOnlyAnAccessGrantSeesTheirHospitals(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => HospitalFactory::createOne(['owner' => $owner]),
            'user' => $user,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[data-testid="dashboard-tile-hospitals"][href="/hospitals"]');
        self::assertSelectorNotExists('[data-testid^="dashboard-tile-access-request"]');
        self::assertSelectorNotExists('[data-testid="onboarding-step-request_clinic_access"]');
    }

    public function testPendingAssignmentRequestIsShownInTileAndOnboardingStep(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $request = $this->storeAssignmentRequest($user, 'Klinikum Nord');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        $requestUrl = '/hospitals/requests#request-'.$request->getPublicIdString();
        self::assertResponseIsSuccessful();
        // A full page load, so the browser marks the linked card as :target (Turbo does not).
        self::assertSelectorExists(sprintf('a[data-testid="dashboard-tile-access-request-pending"][href="%s"][data-turbo="false"]', $requestUrl));
        self::assertSelectorTextContains('[data-testid="dashboard-tile-access-request-pending"]', 'Your request for Klinikum Nord is being reviewed.');
        self::assertSelectorTextContains('[data-testid="onboarding-step-request_clinic_access"] [data-testid="onboarding-request-status"]', 'Pending');
        self::assertSelectorExists(sprintf('[data-testid="onboarding-step-request_clinic_access"] a[data-testid="onboarding-step-action"][href="%s"][data-turbo="false"]', $requestUrl));
        self::assertSelectorNotExists('[data-testid="onboarding-step-request_clinic_access"] form');
    }

    public function testRejectedAssignmentRequestShowsOnlyItsStatus(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $request = $this->storeAssignmentRequest($user, 'Klinikum Nord');
        $request->reject(UserFactory::new()->asAdmin()->create(), 'Please ask your department head first.', new \DateTimeImmutable('2026-11-21'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('a[data-testid="dashboard-tile-access-request-rejected"][href="/hospitals/requests#request-%s"]', $request->getPublicIdString()));
        self::assertSelectorTextContains('[data-testid="dashboard-tile-access-request-rejected"]', 'Request rejected');
        self::assertSelectorTextContains('[data-testid="onboarding-step-request_clinic_access"] [data-testid="onboarding-request-status"]', 'Rejected');
        self::assertSelectorExists('[data-testid="onboarding-step-request_clinic_access"] a[data-testid="onboarding-step-secondary-action"][href="/hospitals/requests/new"]');
        // The reviewer's message stays on the request page.
        self::assertSelectorTextNotContains('body', 'Please ask your department head first.');
    }

    public function testParticipantWithoutClinicSeesOnboardingCard(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'username' => 'onboarding-participant',
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-onboarding-card"]');
        self::assertSelectorExists('[data-testid="onboarding-step-request_clinic_access"]');
        self::assertSelectorExists('[data-testid="onboarding-step-verify_own_clinic"][data-testid-onboarding-locked="true"]');
        self::assertSelectorExists('[data-testid="onboarding-step-start_first_import"][data-testid-onboarding-locked="true"]');
        self::assertSelectorExists('[data-testid="onboarding-step-view_overview_statistics"][data-testid-onboarding-locked="true"]');
        self::assertSelectorExists('[data-testid="onboarding-step-view_explore_data"][data-testid-onboarding-locked="true"]');
        self::assertSelectorNotExists('[data-testid="dashboard-onboarding-progress"]');
        self::assertSelectorNotExists('[data-testid="onboarding-step-request_clinic_access"] form');
    }

    public function testHospitalOwnerSeesOnlyNextSequentialOnboardingStepActionable(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'username' => 'onboarding-owner-sequential',
        ]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-onboarding-card"]');
        self::assertSelectorExists('[data-testid="onboarding-step-verify_own_clinic"]:not([data-testid-onboarding-locked])');
        self::assertSelectorExists('[data-testid="onboarding-step-start_first_import"][data-testid-onboarding-locked="true"]');
        self::assertSelectorExists('[data-testid="onboarding-step-view_explore_data"][data-testid-onboarding-locked="true"]');
        self::assertSelectorExists('[data-testid="onboarding-step-view_overview_statistics"][data-testid-onboarding-locked="true"]');
        self::assertSelectorExists('[data-testid="dashboard-onboarding-progress"]');
    }

    public function testParticipantSeesOnboardingProgressWhenSomeStepsCompleted(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'username' => 'onboarding-progress-user',
        ]);
        UserOnboardingStepFactory::createOne([
            'user' => $user,
            'stepKey' => OnboardingStepKey::ViewExploreData,
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-onboarding-progress"]');
        self::assertSelectorExists('[data-testid="dashboard-onboarding-completed-popover"]');
        self::assertSelectorTextContains('body', '1 of 5 completed');
    }

    public function testOnboardingCardHiddenWhenAllStepsCompleted(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'username' => 'onboarding-complete-user',
        ]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);
        UserOnboardingStepFactory::createOne(['user' => $user, 'stepKey' => OnboardingStepKey::VerifyOwnClinic]);
        UserOnboardingStepFactory::createOne(['user' => $user, 'stepKey' => OnboardingStepKey::StartFirstImport]);
        UserOnboardingStepFactory::createOne(['user' => $user, 'stepKey' => OnboardingStepKey::ViewExploreData]);
        UserOnboardingStepFactory::createOne(['user' => $user, 'stepKey' => OnboardingStepKey::ViewOverviewStatistics]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="dashboard-onboarding-card"]');
    }

    private function storeAssignmentRequest(User $user, string $hospitalName): HospitalAssignmentRequest
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $hospital = HospitalFactory::createOne(['name' => $hospitalName, 'owner' => UserFactory::createOne()]);
        $request = new HospitalAssignmentRequest($user, $hospital, null, HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable('2026-11-20'));
        $entityManager->persist($request);
        $entityManager->flush();

        return $request;
    }

    public function testDashboardSidebarShowsPublishedPostsAndPages(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'dashboard-sidebar-user']);
        PostFactory::createOne([
            'title' => 'Dashboard Sidebar Post',
            'slug' => 'dashboard-sidebar-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-2 hours'),
            'createdBy' => $user,
        ]);
        PageFactory::createOne([
            'title' => 'Dashboard Guide',
            'slug' => 'dashboard-guide',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Guide</p>']]],
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/blog/dashboard-sidebar-post"]');
        self::assertSelectorTextContains('body', 'Dashboard Sidebar Post');
        self::assertSelectorTextNotContains('body', 'No published posts yet.');
        self::assertSelectorTextContains('[data-testid="page-navigation"]', 'Dashboard Guide');
        self::assertSelectorNotExists('[data-testid="page-navigation"] [aria-current]');
    }

    public function testDashboardStaysUsableWhenActivityFrameIsSeparate(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'dashboard-activity-isolated']);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-metrics"]');
        self::assertSelectorExists('a[href="/statistics/"]');
        self::assertSelectorExists('turbo-frame#dashboard-activity[loading="lazy"]');
        self::assertSelectorExists('[data-testid="dashboard-activity-view-all"]');
        self::assertSelectorNotExists('[data-testid="dashboard-activity-item"]');
    }
}
