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
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DefaultControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

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
    }

    public function testAuthenticatedUsersSeeDashboardOnHomepage(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'dashboard-user'])->_real();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'View all Hospitals');
        self::assertSelectorTextContains('body', 'Explore our Data');
        self::assertSelectorTextContains('body', 'Analyze your Data');
        self::assertSelectorTextContains('body', 'Read the Blog');
        self::assertSelectorExists('a[href="/explore/hospital"]');
        self::assertSelectorExists('a[href="/explore"]');
        self::assertSelectorExists('a[href="/statistics/"]');
        self::assertSelectorExists('a[href="/blog"]');
        self::assertSelectorTextContains('body', 'Latest blog posts');
        self::assertSelectorTextContains('body', 'Pages');
        self::assertSelectorTextContains('body', 'No published posts yet.');
        self::assertSelectorTextContains('body', 'No pages available.');
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

        $client->loginUser($user->_real());
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'View your Hospitals');
        self::assertSelectorTextContains('body', 'Import new Allocations');
        self::assertSelectorExists('a[href="/hospitals"]');
        self::assertSelectorExists('a[href="/import/new"]');
    }
}
