<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation;

use App\Allocation\Infrastructure\Security\Voter\ExportVoter;
use App\Content\Application\Page\PageNavigationProvider;
use App\Shared\Application\Navigation\DTO\SitemapLink;
use App\Shared\Application\Navigation\DTO\SitemapSection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SitemapProvider
{
    private const string VISIBILITY_ALWAYS = 'always';
    private const string VISIBILITY_GUEST = 'guest';
    private const string VISIBILITY_AUTHENTICATED = 'authenticated';
    private const string VISIBILITY_PARTICIPANT = 'participant';
    private const string VISIBILITY_EXPORT = 'export';

    /**
     * @var array<string, array{labelKey: string, labelDomain: string}>
     */
    private const array ROUTE_LABELS = [
        'app_default' => ['labelKey' => 'link.home', 'labelDomain' => 'shared'],
        'app_login' => ['labelKey' => 'label.login', 'labelDomain' => 'messages'],
        'app_register' => ['labelKey' => 'label.register', 'labelDomain' => 'messages'],
        'app_forgot_password_request' => ['labelKey' => 'label.forgot_password', 'labelDomain' => 'user'],
        'app_blog_index' => ['labelKey' => 'link.blog', 'labelDomain' => 'shared'],
        'app_cookie_preferences' => ['labelKey' => 'link.cookie_preferences', 'labelDomain' => 'shared'],
        'app_import_index' => ['labelKey' => 'link.import', 'labelDomain' => 'shared'],
        'app_hospitals_export_allocations' => ['labelKey' => 'link.export', 'labelDomain' => 'shared'],
        'app_stats_dashboard' => ['labelKey' => 'link.stats.dashboard', 'labelDomain' => 'shared'],
        'app_stats_analysis_library' => ['labelKey' => 'link.stats.analysis', 'labelDomain' => 'shared'],
        'app_stats_reports' => ['labelKey' => 'link.stats.reports', 'labelDomain' => 'shared'],
        'app_stats_indication_insights' => ['labelKey' => 'link.stats.indication_insights', 'labelDomain' => 'shared'],
        'app_stats_benchmarking' => ['labelKey' => 'link.stats.benchmarking', 'labelDomain' => 'shared'],
        'app_stats_case_flow' => ['labelKey' => 'link.stats.case_flow', 'labelDomain' => 'shared'],
        'app_stats_hospital_population' => ['labelKey' => 'link.stats.hospital_population', 'labelDomain' => 'shared'],
        'app_explore_allocation_list' => ['labelKey' => 'link.allocations', 'labelDomain' => 'shared'],
        'app_explore_assignment_list' => ['labelKey' => 'link.assignments', 'labelDomain' => 'shared'],
        'app_explore_department_list' => ['labelKey' => 'link.departments', 'labelDomain' => 'shared'],
        'app_explore_dispatch_area_list' => ['labelKey' => 'link.dispatch_areas', 'labelDomain' => 'shared'],
        'app_explore_hospital_list' => ['labelKey' => 'link.hospitals', 'labelDomain' => 'shared'],
        'app_explore_indication_list' => ['labelKey' => 'link.indications', 'labelDomain' => 'shared'],
        'app_explore_infection_list' => ['labelKey' => 'link.infections', 'labelDomain' => 'shared'],
        'app_explore_mci_case_list' => ['labelKey' => 'link.mci_cases', 'labelDomain' => 'shared'],
        'app_explore_occasion_list' => ['labelKey' => 'link.occasions', 'labelDomain' => 'shared'],
        'app_explore_secondary_transport_list' => ['labelKey' => 'link.secondary_transports', 'labelDomain' => 'shared'],
        'app_explore_speciality_list' => ['labelKey' => 'link.specialities', 'labelDomain' => 'shared'],
        'app_explore_indication_raw_review_worklist' => ['labelKey' => 'title.indication.review_worklist', 'labelDomain' => 'allocation'],
        'app_settings_index' => ['labelKey' => 'label.settings.my_account', 'labelDomain' => 'user'],
        'app_settings_email' => ['labelKey' => 'label.settings.change_email', 'labelDomain' => 'user'],
        'app_settings_password' => ['labelKey' => 'label.settings.set_new_password', 'labelDomain' => 'user'],
        'app_settings_notifications' => ['labelKey' => 'label.settings.notifications', 'labelDomain' => 'user'],
        'app_hospitals_index' => ['labelKey' => 'label.my_hospitals', 'labelDomain' => 'messages'],
    ];

    /**
     * @var list<array{
     *   key: string,
     *   labelKey: string,
     *   entries: list<array{
     *     route?: string,
     *     visibility: string
     *   }>
     * }>
     */
    private const array SECTIONS = [
        [
            'key' => 'public',
            'labelKey' => 'sitemap.section.public',
            'entries' => [
                ['route' => 'app_default', 'visibility' => self::VISIBILITY_ALWAYS],
                ['route' => 'app_login', 'visibility' => self::VISIBILITY_GUEST],
                ['route' => 'app_register', 'visibility' => self::VISIBILITY_GUEST],
                ['route' => 'app_forgot_password_request', 'visibility' => self::VISIBILITY_GUEST],
                ['route' => 'app_cookie_preferences', 'visibility' => self::VISIBILITY_ALWAYS],
            ],
        ],
        [
            'key' => 'content',
            'labelKey' => 'sitemap.section.content',
            'entries' => [
                ['route' => 'app_blog_index', 'visibility' => self::VISIBILITY_ALWAYS],
            ],
        ],
        [
            'key' => 'explore',
            'labelKey' => 'sitemap.section.explore',
            'entries' => [
                ['route' => 'app_explore_allocation_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_assignment_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_department_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_dispatch_area_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_hospital_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_indication_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_infection_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_mci_case_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_occasion_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_secondary_transport_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_speciality_list', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_explore_indication_raw_review_worklist', 'visibility' => self::VISIBILITY_PARTICIPANT],
            ],
        ],
        [
            'key' => 'statistics',
            'labelKey' => 'sitemap.section.statistics',
            'entries' => [
                ['route' => 'app_stats_dashboard', 'visibility' => self::VISIBILITY_AUTHENTICATED],
                ['route' => 'app_stats_analysis_library', 'visibility' => self::VISIBILITY_AUTHENTICATED],
                ['route' => 'app_stats_reports', 'visibility' => self::VISIBILITY_AUTHENTICATED],
                ['route' => 'app_stats_indication_insights', 'visibility' => self::VISIBILITY_AUTHENTICATED],
                ['route' => 'app_stats_benchmarking', 'visibility' => self::VISIBILITY_AUTHENTICATED],
                ['route' => 'app_stats_case_flow', 'visibility' => self::VISIBILITY_AUTHENTICATED],
                ['route' => 'app_stats_hospital_population', 'visibility' => self::VISIBILITY_AUTHENTICATED],
            ],
        ],
        [
            'key' => 'data_exchange',
            'labelKey' => 'sitemap.section.data_exchange',
            'entries' => [
                ['route' => 'app_import_index', 'visibility' => self::VISIBILITY_PARTICIPANT],
                ['route' => 'app_hospitals_export_allocations', 'visibility' => self::VISIBILITY_EXPORT],
            ],
        ],
        [
            'key' => 'account',
            'labelKey' => 'sitemap.section.account',
            'entries' => [
                ['route' => 'app_settings_index', 'visibility' => self::VISIBILITY_AUTHENTICATED],
                ['route' => 'app_settings_email', 'visibility' => self::VISIBILITY_AUTHENTICATED],
                ['route' => 'app_settings_password', 'visibility' => self::VISIBILITY_AUTHENTICATED],
                ['route' => 'app_settings_notifications', 'visibility' => self::VISIBILITY_AUTHENTICATED],
                ['route' => 'app_hospitals_index', 'visibility' => self::VISIBILITY_PARTICIPANT],
            ],
        ],
    ];

    public function __construct(
        private PageNavigationProvider $pageNavigationProvider,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @return list<SitemapSection>
     */
    public function getSections(): array
    {
        $sections = [];

        foreach (self::SECTIONS as $sectionDefinition) {
            $pageTree = [];
            $links = $this->orderSectionLinks(
                $sectionDefinition['key'],
                $this->buildSectionLinks($sectionDefinition),
            );

            if ('content' === $sectionDefinition['key']) {
                $pageTree = $this->pageNavigationProvider->getVisiblePublishedPageTree();
            }

            if ([] === $links && [] === $pageTree) {
                continue;
            }

            $sections[] = new SitemapSection(
                key: $sectionDefinition['key'],
                labelKey: $sectionDefinition['labelKey'],
                links: $links,
                pageTree: $pageTree,
            );
        }

        return $sections;
    }

    /**
     * @param array{
     *   key: string,
     *   labelKey: string,
     *   entries: list<array{route?: string, visibility: string}>
     * } $sectionDefinition
     *
     * @return list<SitemapLink>
     */
    private function buildSectionLinks(array $sectionDefinition): array
    {
        $links = [];

        foreach ($sectionDefinition['entries'] as $entry) {
            if (!$this->isVisible($entry['visibility'])) {
                continue;
            }

            $link = $this->buildRouteLink($entry['route'] ?? '');
            if ($link instanceof SitemapLink) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * @param list<SitemapLink> $links
     *
     * @return list<SitemapLink>
     */
    private function orderSectionLinks(string $sectionKey, array $links): array
    {
        return match ($sectionKey) {
            'public', 'account' => $links,
            'statistics' => $this->pinFirstRouteLink('app_stats_dashboard', $links),
            default => $this->sortAlphabetically($links),
        };
    }

    /**
     * @param list<SitemapLink> $links
     *
     * @return list<SitemapLink>
     */
    private function pinFirstRouteLink(string $routeName, array $links): array
    {
        $pinnedUrl = $this->urlGenerator->generate($routeName);
        $pinned = null;
        $rest = [];

        foreach ($links as $link) {
            if ($link->url === $pinnedUrl) {
                $pinned = $link;

                continue;
            }

            $rest[] = $link;
        }

        $orderedRest = $this->sortAlphabetically($rest);

        return null === $pinned ? $orderedRest : [$pinned, ...$orderedRest];
    }

    /**
     * @param list<SitemapLink> $links
     *
     * @return list<SitemapLink>
     */
    private function sortAlphabetically(array $links): array
    {
        usort(
            $links,
            static fn (SitemapLink $left, SitemapLink $right): int => strcasecmp($left->label, $right->label),
        );

        return $links;
    }

    private function isVisible(string $visibility): bool
    {
        return match ($visibility) {
            self::VISIBILITY_ALWAYS => true,
            self::VISIBILITY_GUEST => !$this->authorizationChecker->isGranted('ROLE_USER'),
            self::VISIBILITY_AUTHENTICATED => $this->authorizationChecker->isGranted('ROLE_USER'),
            self::VISIBILITY_PARTICIPANT => $this->authorizationChecker->isGranted('ROLE_PARTICIPANT'),
            self::VISIBILITY_EXPORT => $this->authorizationChecker->isGranted(ExportVoter::EXPORT),
            default => false,
        };
    }

    private function buildRouteLink(string $routeName): ?SitemapLink
    {
        if ('' === $routeName) {
            return null;
        }

        $labelDefinition = self::ROUTE_LABELS[$routeName] ?? null;
        if (null === $labelDefinition) {
            return null;
        }

        return new SitemapLink(
            url: $this->urlGenerator->generate($routeName),
            label: $this->translator->trans(
                $labelDefinition['labelKey'],
                [],
                $labelDefinition['labelDomain'],
            ),
        );
    }
}
