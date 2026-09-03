<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
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
use App\User\Domain\Factory\UserFactory;
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
        self::assertSelectorTextContains('body', 'Pages');
        self::assertSelectorTextContains('body', 'No published posts yet.');
        self::assertSelectorTextContains('body', 'No pages available.');
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
        self::assertSelectorTextContains('body', 'View all Hospitals');
        self::assertSelectorTextContains('body', 'Explore our Data');
        self::assertSelectorTextContains('body', 'Analyze your Data');
        self::assertSelectorNotExists('[data-testid="dashboard-participant-access-notice"]');
        self::assertSelectorExists('a[href="/explore/hospital"]');
        self::assertSelectorExists('a[href="/explore"]');
        self::assertSelectorExists('a[data-testid="dashboard-metric-allocations"][href="/explore/allocation"]');
        self::assertSelectorExists('a[data-testid="dashboard-metric-hospitals"][href="/explore/hospital?participating=1"]');
        self::assertSelectorExists('a[data-testid="dashboard-metric-users"][href="/explore/user"]');
        self::assertSelectorExists('a[data-testid="dashboard-metric-imports"][href="/import"]');

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
        self::assertSelectorExists('a[href="/hospitals"]');
        self::assertSelectorExists('a[href="/import/new"]');
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
        self::assertSelectorTextContains('body', 'Dashboard Guide');
        self::assertSelectorTextNotContains('body', 'No pages available.');
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
