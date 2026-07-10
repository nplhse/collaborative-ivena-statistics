<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Navigation;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Shared\Application\Navigation\DTO\SitemapPageNode;
use App\Shared\Application\Navigation\DTO\SitemapSection;
use App\Shared\Application\Navigation\SitemapProvider;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

    public function testGuestSeesPublicAndContentSections(): void
    {
        $this->seedContentPages();

        $sectionKeys = $this->sectionKeys($this->provider->getSections());

        self::assertSame(['public', 'content'], $sectionKeys);
        self::assertNotContains('statistics', $sectionKeys);
        self::assertNotContains('explore', $sectionKeys);
        self::assertNotContains('account', $sectionKeys);
        self::assertNotContains('admin', $sectionKeys);

        $publicSection = $this->findSection('public');
        $publicLabels = array_map(static fn (\App\Shared\Application\Navigation\DTO\SitemapLink $link): string => $link->label, $publicSection->links);
        $publicUrls = array_map(static fn (\App\Shared\Application\Navigation\DTO\SitemapLink $link): string => $link->url, $publicSection->links);

        self::assertContains('Login', $publicLabels);
        self::assertContains('Register', $publicLabels);
        self::assertSame('Home', $publicSection->links[0]->label);
        self::assertFalse(
            (bool) array_filter($publicUrls, static fn (string $url): bool => str_contains($url, '/blog')),
        );
        self::assertSame(
            ['Home', 'Login', 'Register', 'Forgot password?', 'Cookie settings'],
            $publicLabels,
        );

        $contentSection = $this->findSection('content');
        self::assertCount(1, $contentSection->links);
        self::assertSame('Blog', $contentSection->links[0]->label);
        self::assertNotEmpty($contentSection->pageTree);

        $pageTreeLabels = $this->flattenPageTreeLabels($contentSection->pageTree);
        self::assertContains('Page about', $pageTreeLabels);
        self::assertContains('Custom guide', $pageTreeLabels);
        self::assertContains('Child page', $pageTreeLabels);
        self::assertNotContains('Members only', $pageTreeLabels);
    }

    public function testContentSectionRendersNestedPagesAsTree(): void
    {
        $this->seedContentPages();

        $contentSection = $this->findSection('content');
        $guidesNode = $this->findPageTreeNodeByLabel($contentSection->pageTree, 'Guides');

        self::assertNotNull($guidesNode);
        self::assertCount(2, $guidesNode->children);

        $childLabels = array_map(
            static fn (SitemapPageNode $node): string => $node->label,
            $guidesNode->children,
        );

        self::assertSame(['Custom guide', 'Child page'], $childLabels);
        self::assertStringContainsString('/guides/child-page', $guidesNode->children[1]->url);
    }

    public function testAuthenticatedUserSeesStatisticsAndAccountSections(): void
    {
        $this->seedContentPages();
        $this->authenticate(UserFactory::createOne(['roles' => ['ROLE_USER']]));

        $sectionKeys = $this->sectionKeys($this->provider->getSections());

        self::assertSame(['public', 'content', 'statistics', 'account'], $sectionKeys);
        self::assertNotContains('explore', $sectionKeys);
        self::assertNotContains('data_exchange', $sectionKeys);
        self::assertNotContains('admin', $sectionKeys);

        $publicSection = $this->findSection('public');
        $publicLabels = array_map(static fn (\App\Shared\Application\Navigation\DTO\SitemapLink $link): string => $link->label, $publicSection->links);

        self::assertNotContains('Login', $publicLabels);
        self::assertNotContains('Register', $publicLabels);

        $contentSection = $this->findSection('content');
        $pageTreeLabels = $this->flattenPageTreeLabels($contentSection->pageTree);

        self::assertContains('Members only', $pageTreeLabels);

        $statisticsSection = $this->findSection('statistics');
        self::assertCount(7, $statisticsSection->links);
        $this->assertLabelsAreAlphabetical(array_map(
            static fn (\App\Shared\Application\Navigation\DTO\SitemapLink $link): string => $link->label,
            $statisticsSection->links,
        ));
    }

    public function testParticipantSeesExploreDataExchangeAndMyHospitalsInAccount(): void
    {
        $this->seedContentPages();
        $this->authenticate(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));

        $sectionKeys = $this->sectionKeys($this->provider->getSections());

        self::assertSame(
            ['public', 'content', 'explore', 'statistics', 'data_exchange', 'account'],
            $sectionKeys,
        );

        $exploreSection = $this->findSection('explore');
        self::assertCount(12, $exploreSection->links);
        $this->assertLabelsAreAlphabetical(array_map(
            static fn (\App\Shared\Application\Navigation\DTO\SitemapLink $link): string => $link->label,
            $exploreSection->links,
        ));

        $dataExchangeSection = $this->findSection('data_exchange');
        self::assertCount(1, $dataExchangeSection->links);
        self::assertSame('Import', $dataExchangeSection->links[0]->label);

        $accountSection = $this->findSection('account');
        self::assertCount(5, $accountSection->links);
        self::assertSame('My hospitals', $accountSection->links[4]->label);
        self::assertSame('My Account', $accountSection->links[0]->label);
    }

    public function testExportLinkOnlyVisibleInDataExchangeSectionWhenUserCanExport(): void
    {
        $this->seedContentPages();

        $participantWithoutExport = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->authenticate($participantWithoutExport);

        self::assertFalse($this->sectionContainsRoute($this->provider->getSections(), 'app_hospitals_export_allocations'));

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        HospitalFactory::createOne(['owner' => $owner]);
        $this->authenticate($owner);

        self::assertTrue($this->sectionContainsRoute($this->provider->getSections(), 'app_hospitals_export_allocations'));

        $dataExchangeSection = $this->findSection('data_exchange');
        self::assertCount(2, $dataExchangeSection->links);
        self::assertSame('Export', $dataExchangeSection->links[0]->label);
        self::assertSame('Import', $dataExchangeSection->links[1]->label);

        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([
                HospitalPermission::View,
                HospitalPermission::Export,
            ]),
        ]);
        $this->authenticate($grantee);

        self::assertTrue($this->sectionContainsRoute($this->provider->getSections(), 'app_hospitals_export_allocations'));
    }

    public function testAdminDoesNotSeeAdministrationSection(): void
    {
        $this->seedContentPages();
        $this->authenticate(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_PARTICIPANT']]));

        $sectionKeys = $this->sectionKeys($this->provider->getSections());

        self::assertNotContains('admin', $sectionKeys);
        self::assertFalse($this->sectionContainsRoute($this->provider->getSections(), 'app_admin_dashboard'));
    }

    public function testContentSectionShowsBlogWithoutPublishedPages(): void
    {
        $sectionKeys = $this->sectionKeys($this->provider->getSections());

        self::assertContains('content', $sectionKeys);

        $publicSection = $this->findSection('public');
        self::assertSame('Home', $publicSection->links[0]->label);

        $contentSection = $this->findSection('content');
        self::assertCount(1, $contentSection->links);
        self::assertSame('Blog', $contentSection->links[0]->label);
        self::assertSame([], $contentSection->pageTree);
    }

    private function seedContentPages(): void
    {
        foreach ([PageKey::About, PageKey::Features, PageKey::Faq, PageKey::Imprint, PageKey::Privacy, PageKey::Terms] as $pageKey) {
            PageFactory::createOne([
                'title' => 'Page '.$pageKey->value,
                'slug' => $pageKey->value,
                'path' => '/'.$pageKey->value,
                'key' => $pageKey,
                'status' => Page::STATUS_PUBLISHED,
                'visibility' => Page::VISIBILITY_PUBLIC,
            ]);
        }

        $guidesParent = PageFactory::createOne([
            'title' => 'Guides',
            'slug' => 'guides',
            'path' => '/guides',
            'key' => null,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 1,
        ]);

        PageFactory::createOne([
            'title' => 'Custom guide',
            'slug' => 'custom-guide',
            'path' => '/guides/custom-guide',
            'parent' => $guidesParent,
            'key' => null,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 1,
        ]);

        PageFactory::createOne([
            'title' => 'Child page',
            'slug' => 'child-page',
            'path' => '/guides/child-page',
            'parent' => $guidesParent,
            'key' => null,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 2,
        ]);

        PageFactory::createOne([
            'title' => 'Members only',
            'slug' => 'members-only',
            'path' => '/members-only',
            'key' => null,
            'status' => Page::STATUS_PUBLISHED,
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
     * @param list<SitemapPageNode> $nodes
     *
     * @return list<string>
     */
    private function flattenPageTreeLabels(array $nodes): array
    {
        $labels = [];

        foreach ($nodes as $node) {
            $labels[] = $node->label;
            $labels = [...$labels, ...$this->flattenPageTreeLabels($node->children)];
        }

        return $labels;
    }

    /**
     * @param list<SitemapPageNode> $nodes
     */
    private function findPageTreeNodeByLabel(array $nodes, string $label): ?SitemapPageNode
    {
        foreach ($nodes as $node) {
            if ($node->label === $label) {
                return $node;
            }

            $match = $this->findPageTreeNodeByLabel($node->children, $label);
            if ($match instanceof SitemapPageNode) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param list<string> $labels
     */
    private function assertLabelsAreAlphabetical(array $labels): void
    {
        $sorted = $labels;
        usort($sorted, strcasecmp(...));

        self::assertSame($sorted, $labels);
    }

    /**
     * @param list<SitemapSection> $sections
     *
     * @return list<string>
     */
    private function sectionKeys(array $sections): array
    {
        return array_map(static fn (SitemapSection $section): string => $section->key, $sections);
    }

    private function findSection(string $key): SitemapSection
    {
        foreach ($this->provider->getSections() as $section) {
            if ($section->key === $key) {
                return $section;
            }
        }

        self::fail(sprintf('Expected sitemap section "%s" to exist.', $key));
    }

    /**
     * @param list<SitemapSection> $sections
     */
    private function sectionContainsRoute(array $sections, string $routeName): bool
    {
        $url = self::getContainer()->get(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class)->generate($routeName);

        foreach ($sections as $section) {
            foreach ($section->links as $link) {
                if ($link->url === $url) {
                    return true;
                }
            }
        }

        return false;
    }
}
