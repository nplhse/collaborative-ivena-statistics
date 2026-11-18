<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Navigation;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Shared\Application\Navigation\DTO\SitemapEntry;
use App\Shared\Application\Navigation\DTO\SitemapGroup;
use App\Shared\Application\Navigation\DTO\SitemapSection;
use App\Shared\Application\Navigation\SitemapProvider;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class SitemapProviderTest extends KernelTestCase
{
    use Factories;

    private SitemapProvider $provider;

    private TokenStorageInterface $tokenStorage;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->provider = self::getContainer()->get(SitemapProvider::class);
        $this->tokenStorage = self::getContainer()->get(TokenStorageInterface::class);
    }

    public function testGuestSeesPublicAreasFooterColumnsAndRemainingPages(): void
    {
        $this->seedContentPages();

        self::assertSame(
            ['blog', 'page-about', 'page-features', 'page-faq', 'footer-legal', 'footer-more', 'pages'],
            $this->sectionKeys(),
        );
        self::assertSame(['Page imprint', 'Page privacy', 'Page terms'], $this->labels($this->findSection('footer-legal')->groups[0]->entries));
        self::assertSame(['Cookie settings'], $this->labels($this->findSection('footer-more')->groups[0]->entries), 'The sitemap does not link to itself.');

        $pages = $this->findSection('pages')->groups[0]->entries;
        self::assertSame(['Guides'], $this->labels($pages), 'Pages already listed as area or footer link are not repeated; members-only pages are hidden.');
        self::assertSame(['Custom guide', 'Child page'], $this->labels($pages[0]->children));
        self::assertStringContainsString('/guides/child-page', (string) $pages[0]->children[1]->url);
    }

    public function testUserSeesStatisticsWithSidebarGroupsAndNestedInsights(): void
    {
        $this->seedContentPages();
        $this->authenticate(UserFactory::createOne(['roles' => ['ROLE_USER']]));

        self::assertSame(
            ['statistics', 'blog', 'page-faq', 'footer-project', 'footer-legal', 'footer-more', 'pages'],
            $this->sectionKeys(),
        );

        $statistics = $this->findSection('statistics');
        self::assertSame($this->url('app_stats_dashboard'), $statistics->url);
        self::assertSame(
            [null, 'Allocations', 'Comparisons', 'Capacity & closures', 'Own analyses', null],
            array_map(static fn (SitemapGroup $group): ?string => $group->label, $statistics->groups),
        );
        self::assertSame(['Overview', 'Reports', 'Top Lists'], $this->labels($statistics->groups[0]->entries));
        self::assertSame(['Forced assignments'], $this->labels($statistics->groups[3]->entries), 'Closures need the beta role.');
        self::assertSame(['Geographic flow', 'Hospitals'], $this->labels($statistics->groups[5]->entries));

        $insights = $statistics->groups[1]->entries[0];
        self::assertSame('Insights', $insights->label);
        self::assertNull($insights->url, 'A group entry of the sidebar only heads its children.');
        self::assertSame(['Overview', 'Dimensions', 'Comparison'], $this->labels($insights->children));
        self::assertSame(
            ['Assignment', 'Departments', 'Indication groups', 'Indications', 'Infections', 'Occasions', 'Secondary transports', 'Specialities'],
            $this->labels($insights->children[1]->children),
        );

        self::assertSame(['Page about', 'Page features', 'Activity'], $this->labels($this->findSection('footer-project')->groups[0]->entries));
        self::assertSame(['Members only', 'Guides'], $this->labels($this->findSection('pages')->groups[0]->entries));
    }

    public function testParticipantSeesExploreImportAndTools(): void
    {
        $this->seedContentPages();
        $this->authenticate(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));

        self::assertSame(
            ['explore', 'statistics', 'import', 'tools', 'blog', 'page-faq', 'footer-project', 'footer-legal', 'footer-more', 'pages'],
            $this->sectionKeys(),
        );

        $explore = $this->findSection('explore');
        self::assertSame(
            [null, 'Cases', 'Organisation', 'Hospital structure', 'Features', null],
            array_map(static fn (SitemapGroup $group): ?string => $group->label, $explore->groups),
        );
        self::assertSame(['Hospitals', 'States', 'Dispatch Areas', 'Users'], $this->labels($explore->groups[2]->entries), 'Users come from the User context.');

        self::assertSame([], $this->findSection('import')->groups, 'Import has no sub-pages and becomes a link tile.');

        $tools = $this->findSection('tools');
        self::assertSame($this->url('app_tools_index'), $tools->url);
        self::assertSame(['Indication matching review'], $this->labels($tools->groups[0]->entries));
    }

    public function testBetaRolesAddClosuresTreatmentDataAndBetaTools(): void
    {
        $this->seedContentPages();
        $this->authenticate(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA']]));

        $statistics = $this->findSection('statistics');
        self::assertSame(
            [null, 'Allocations', 'Comparisons', 'Capacity & closures', 'Clinical data', 'Own analyses', null],
            array_map(static fn (SitemapGroup $group): ?string => $group->label, $statistics->groups),
        );

        [$forcedAssignments, $closures] = $statistics->groups[3]->entries;
        self::assertSame('Forced assignments', $forcedAssignments->label);
        self::assertSame('Closures', $closures->label);
        self::assertNull($closures->badge, 'Closure Analytics left the beta (#1350).');
        self::assertSame(
            ['Overview', 'Duration and time burden', 'Timeline drilldown', 'Groups and events', 'Constellations'],
            $this->labels($closures->children),
        );
        // The ED visit plugin and, in the test environment, the inpatient_stay test bundle add their statistics pages.
        self::assertSame(['Treatment data', 'Inpatient stays (test)'], $this->labels($statistics->groups[4]->entries));

        self::assertSame(
            [['Encounter assignment conflicts', 'Encounter match review', 'Encounter match validation', 'Indication matching review'], ['Data preparation tool']],
            array_map(fn (SitemapGroup $group): array => $this->labels($group->entries), $this->findSection('tools')->groups),
        );
    }

    public function testExportIsListedOnlyWhenTheUserCanExport(): void
    {
        $this->seedContentPages();

        $this->authenticate(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        self::assertNotContains('export', $this->sectionKeys());

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        HospitalFactory::createOne(['owner' => $owner]);
        $this->authenticate($owner);
        self::assertSame($this->url('app_hospitals_export_allocations'), $this->findSection('export')->url);

        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => HospitalFactory::createOne(['owner' => $owner]),
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([
                HospitalPermission::View,
                HospitalPermission::Export,
            ]),
        ]);
        $this->authenticate($grantee);
        self::assertContains('export', $this->sectionKeys());
    }

    public function testAccountAdminAndLoginPagesAreNotListed(): void
    {
        $this->seedContentPages();
        $hidden = ['app_login', 'app_register', 'app_forgot_password_request', 'app_settings_index', 'app_hospitals_index', 'app_admin_dashboard'];

        self::assertSame([], array_intersect(array_map($this->url(...), $hidden), $this->allUrls()), 'Guest');

        $this->authenticate(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_PARTICIPANT']]));
        self::assertSame([], array_intersect(array_map($this->url(...), $hidden), $this->allUrls()), 'Admin');
    }

    public function testEveryUrlIsListedOnce(): void
    {
        $this->seedContentPages();
        $this->authenticate(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA']]));

        $urls = $this->allUrls(withSectionUrls: false);

        self::assertSame(array_values(array_unique($urls)), $urls);
    }

    private function seedContentPages(): void
    {
        foreach ([PageKey::About, PageKey::Features, PageKey::Faq, PageKey::Imprint, PageKey::Privacy, PageKey::Terms] as $pageKey) {
            PageFactory::createOne([
                'title' => 'Page '.$pageKey->value,
                'slug' => $pageKey->value,
                'path' => '/'.$pageKey->value,
                'key' => $pageKey,
                'status' => PageTranslation::STATUS_PUBLISHED,
                'visibility' => Page::VISIBILITY_PUBLIC,
            ]);
        }

        $guidesParent = PageFactory::createOne([
            'title' => 'Guides',
            'slug' => 'guides',
            'path' => '/guides',
            'key' => null,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 1,
        ]);

        PageFactory::createOne([
            'title' => 'Custom guide',
            'slug' => 'custom-guide',
            'path' => '/guides/custom-guide',
            'parent' => $guidesParent,
            'key' => null,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 1,
        ]);

        PageFactory::createOne([
            'title' => 'Child page',
            'slug' => 'child-page',
            'path' => '/guides/child-page',
            'parent' => $guidesParent,
            'key' => null,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 2,
        ]);

        PageFactory::createOne([
            'title' => 'Members only',
            'slug' => 'members-only',
            'path' => '/members-only',
            'key' => null,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);
    }

    private function authenticate(User $user): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));
    }

    /**
     * @return list<string>
     */
    private function sectionKeys(): array
    {
        return array_map(static fn (SitemapSection $section): string => $section->key, $this->provider->getSections());
    }

    private function findSection(string $key): SitemapSection
    {
        foreach ($this->provider->getSections() as $section) {
            if ($section->key === $key) {
                return $section;
            }
        }

        self::fail(\sprintf('Expected sitemap section "%s" to exist.', $key));
    }

    /**
     * @param list<SitemapEntry> $entries
     *
     * @return list<string>
     */
    private function labels(array $entries): array
    {
        return array_map(static fn (SitemapEntry $entry): string => $entry->label, $entries);
    }

    /**
     * @return list<string>
     */
    private function allUrls(bool $withSectionUrls = true): array
    {
        $urls = [];
        $collect = static function (array $entries) use (&$collect, &$urls): void {
            /** @var list<SitemapEntry> $entries */
            foreach ($entries as $entry) {
                if (null !== $entry->url) {
                    $urls[] = $entry->url;
                }

                $collect($entry->children);
            }
        };

        foreach ($this->provider->getSections() as $section) {
            if ($withSectionUrls && null !== $section->url) {
                $urls[] = $section->url;
            }

            foreach ($section->groups as $group) {
                $collect($group->entries);
            }
        }

        return $urls;
    }

    private function url(string $route): string
    {
        return self::getContainer()->get(UrlGeneratorInterface::class)->generate($route);
    }
}
