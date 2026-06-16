<?php

declare(strict_types=1);

namespace App\DataFixtures\Content;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PostCategoryFactory;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Factory\PostTagFactory;
use App\User\Domain\Factory\UserFactory;

final class DemoContentLoader
{
    public function load(): void
    {
        $this->loadPages();
        $this->loadBlog();
    }

    private function loadPages(): void
    {
        PageFactory::createOne([
            'title' => 'Imprint',
            'slug' => 'imprint',
            'path' => '/imprint',
            'key' => PageKey::Imprint,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 100,
            'content' => [
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'Service provider', 'level' => 'h1', 'align' => 'left', 'spacingBefore' => 'none', 'spacingAfter' => 'md'],
                ],
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<pre>Collaborative IVENA Statistics Demo
12 Sample Street
60311 Frankfurt am Main
Germany</pre><p><strong>Contact:</strong> <a href="mailto:demo@example.org">demo@example.org</a></p>',
                    ],
                ],
            ],
        ]);

        PageFactory::createOne([
            'title' => 'Terms & Conditions',
            'slug' => 'terms-conditions',
            'path' => '/terms-conditions',
            'key' => PageKey::Terms,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 90,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<p>Use of this platform requires acceptance of the collaborative IVENA statistics participation terms.</p>'
                            .'<h2>Goals</h2><ul>'
                            .'<li>Operate a shared platform for emergency care research based on IVENA allocation data.</li>'
                            .'<li>Enable benchmarking against anonymised hospital clusters.</li>'
                            .'<li>Support quality assurance within participating hospitals.</li></ul>'
                            .'<h2>Participation</h2><p>Participating hospitals import their own IVENA exports. '
                            .'Direct access to raw database exports is not provided. Competitive comparisons between named competitors are not permitted.</p>',
                    ],
                ],
            ],
        ]);

        PageFactory::createOne([
            'title' => 'About us',
            'slug' => 'about-us',
            'path' => '/about-us',
            'key' => PageKey::About,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 10,
            'content' => [
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'About Collaborative IVENA Statistics', 'level' => 'h2', 'align' => 'left', 'spacingBefore' => 'none', 'spacingAfter' => 'md'],
                ],
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<p>Emergency medical services are among the largest referrers to emergency departments, yet large-scale analyses of EMS patient characteristics remain rare. '
                            .'Since 2017, the web-based IVENA system has been used to register EMS patients digitally at acute care hospitals across Germany.</p>'
                            .'<p>Each allocation produces an anonymised dataset with age, urgency, speciality, clinical flags, and requested resources such as resuscitation room or cath lab capacity.</p>',
                    ],
                ],
                [
                    'type' => 'highlight',
                    'enabled' => true,
                    'data' => [
                        'variant' => 'info',
                        'title' => 'Collaborative research',
                        'html' => '<p>This platform merges IVENA allocation data from many hospitals to enable shared statistics and dedicated analyses for emergency care research questions.</p>',
                        'iconMode' => 'auto',
                    ],
                ],
                [
                    'type' => 'quote',
                    'enabled' => true,
                    'data' => ['text' => 'Better data together — understanding EMS allocations at scale.'],
                ],
            ],
        ]);

        PageFactory::createOne([
            'title' => 'FAQ',
            'slug' => 'faq',
            'path' => '/faq',
            'key' => PageKey::Faq,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 50,
            'content' => [
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'How do I import IVENA data?', 'level' => 'h1', 'align' => 'left', 'spacingBefore' => 'none', 'spacingAfter' => 'md'],
                ],
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<p>Export allocations from IVENA as CSV (recommended: quarterly batches of up to 5,000–10,000 rows). '
                            .'Then open <strong>Imports → Create New Import</strong>, select your hospital, upload the file, and start processing.</p>',
                    ],
                ],
                [
                    'type' => 'cta',
                    'enabled' => true,
                    'data' => [
                        'headline' => 'Need the full participation guide?',
                        'buttonLabel' => 'Read the import documentation',
                        'buttonUrl' => '/faq',
                    ],
                ],
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'Common issues', 'level' => 'h2', 'align' => 'left', 'spacingBefore' => 'md', 'spacingAfter' => 'sm'],
                ],
                [
                    'type' => 'accordion',
                    'enabled' => true,
                    'data' => [
                        'items' => [
                            [
                                'title' => 'The IVENA export fails',
                                'html' => '<p>Reduce the date range and export quarter by quarter.</p>',
                                'openByDefault' => true,
                            ],
                            [
                                'title' => 'The import takes a long time',
                                'html' => '<p>Large CSV files need more processing time. Use the status page to monitor progress.</p>',
                                'openByDefault' => false,
                            ],
                            [
                                'title' => 'Some rows were rejected',
                                'html' => '<p>The import pipeline validates plausibility. Rejected rows are listed in the import report.</p>',
                                'openByDefault' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        PageFactory::createOne([
            'title' => 'Features',
            'slug' => 'features',
            'path' => '/features',
            'key' => PageKey::Features,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 20,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<h2>Platform features</h2><ul>'
                            .'<li>IVENA CSV import with validation and reporting</li>'
                            .'<li>Interactive statistics and benchmarking dashboards</li>'
                            .'<li>Hospital-scoped access control for associated users</li></ul>',
                    ],
                ],
            ],
        ]);
    }

    private function loadBlog(): void
    {
        $devlog = PostCategoryFactory::createOne(['name' => 'Devlog', 'slug' => 'devlog']);
        $news = PostCategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        $platform = PostCategoryFactory::createOne(['name' => 'Platform', 'slug' => 'platform']);
        $research = PostCategoryFactory::createOne(['name' => 'Research', 'slug' => 'research']);

        $tagRelease = PostTagFactory::createOne(['name' => 'Release', 'slug' => 'release']);
        $tagStatistics = PostTagFactory::createOne(['name' => 'Statistics', 'slug' => 'statistics']);
        $tagImport = PostTagFactory::createOne(['name' => 'Import', 'slug' => 'import']);
        $tagBenchmarking = PostTagFactory::createOne(['name' => 'Benchmarking', 'slug' => 'benchmarking']);
        $tagCollaboration = PostTagFactory::createOne(['name' => 'Collaboration', 'slug' => 'collaboration']);

        $posts = [
            [
                'title' => 'The coffee was still warm when the first emails of the day arrived.',
                'slug' => 'coffee-still-warm-first-emails',
                'content' => '<p>After months of groundwork, the first alpha of Collaborative IVENA Statistics is available to a small group of participating hospitals. '
                    .'The goal is not a polished product launch, but a working proof of concept that shows how IVENA allocation exports can be merged, validated, and explored in one shared environment.</p>'
                    .'<p>At this stage, the platform focuses on the essentials: secure hospital-scoped access, CSV import with reporting, and a first set of overview statistics. '
                    .'Several features are deliberately limited, including email notifications, large-scale import batches, and advanced benchmarking views.</p>'
                    .'<p>Early feedback from emergency department coordinators has already highlighted where the workflow feels intuitive and where more guidance is needed. '
                    .'In particular, hospitals asked for clearer import status pages and simpler explanations when rows are rejected during validation.</p>'
                    .'<p>We are treating this release as a learning phase. Participating sites can experiment with quarterly exports, compare their own trends over time, and help us prioritise the next development steps.</p>'
                    .'<p>If you are part of the pilot group, thank you for testing the platform under real operational conditions. Your comments directly shape what we build next.</p>',
                'category' => $news,
                'tags' => [$tagRelease, $tagCollaboration],
                'status' => PostStatus::PUBLISHED,
            ],
            [
                'title' => 'Sometimes the shortest walk home takes the longest.',
                'slug' => 'shortest-walk-home-takes-longest',
                'content' => '<p>One of the first pain points after importing several thousand allocations was the time required to load the overview dashboard. '
                    .'Repeated aggregate queries across large fact tables made the page feel sluggish, especially when users switched between hospitals or date ranges.</p>'
                    .'<p>We merged several redundant database queries and introduced materialised views for the most common overview metrics. '
                    .'The result is a much snappier experience: median load time on the overview page dropped by roughly 80% in our internal benchmarks.</p>'
                    .'<p>Behind the scenes, the statistics module now reads from pre-aggregated projections instead of scanning raw allocation rows for every chart refresh. '
                    .'This also reduces database load during peak usage in the morning, when many coordinators review the previous day\'s figures.</p>'
                    .'<p>There is still room for improvement on deeply filtered analyses and long historical ranges. '
                    .'Those views will be addressed in a follow-up iteration once the new projection pipeline has proven stable in production-like fixture volumes.</p>'
                    .'<p>For now, the performance gain should make daily monitoring far more practical, even for hospitals with high allocation throughput.</p>'
                    .'<p>We will publish more technical detail on the projection design in a later post once the rebuild command and monitoring hooks are fully documented.</p>',
                'category' => $devlog,
                'tags' => [$tagStatistics, $tagRelease],
                'status' => PostStatus::PUBLISHED,
            ],
            [
                'title' => 'She left the window open just enough to hear the rain.',
                'slug' => 'window-open-enough-to-hear-rain',
                'content' => '<p>Data quality during IVENA imports depends on dozens of small normalisation rules. '
                    .'When dispatch area names or department labels differ slightly between exports, rows used to fail validation even though the underlying clinical meaning was clear.</p>'
                    .'<p>Recent changes improve matching for dispatch areas, assessment fields, and department mappings. '
                    .'The import pipeline now applies a consistent normalisation layer before plausibility checks run, which reduces false rejections without weakening data integrity.</p>'
                    .'<p>Hospitals that regularly import quarterly batches should see fewer unexplained rejections in the import report. '
                    .'Where a value still cannot be mapped, the report now includes more context so coordinators can fix source data or request a reference update.</p>'
                    .'<p>We also tightened logging around edge cases such as missing secondary transport flags and ambiguous speciality codes. '
                    .'That makes it easier for support staff to trace a rejected row back to the exact validation rule.</p>'
                    .'<p>These improvements are especially relevant for sites joining the collaborative network with historical exports spanning several years.</p>',
                'category' => $platform,
                'tags' => [$tagImport, $tagCollaboration],
                'status' => PostStatus::PUBLISHED,
            ],
            [
                'title' => 'There is a particular quiet in cities just before sunrise.',
                'slug' => 'quiet-in-cities-before-sunrise',
                'content' => '<p>Benchmarking has been one of the most requested capabilities since the project started. '
                    .'Hospitals want to understand how their allocation patterns compare to anonymised peer groups without exposing identifiable competitor data.</p>'
                    .'<p>The new statistics explorer adds configurable analyses with saved scopes, filters for urgency and speciality, and first benchmarking views that compare a hospital against size-based clusters.</p>'
                    .'<p>All comparisons remain aggregated and anonymised. Named hospitals outside a user\'s own organisation are never shown side by side in a competitive layout.</p>'
                    .'<p>Early testers used the explorer to review seasonal shifts in paediatric transfers and to monitor how often specific clinical flags appear in high-urgency cases. '
                    .'Those workflows informed default chart presets and the layout of the filter panel.</p>'
                    .'<p>We plan to extend benchmarking with additional cluster dimensions and export options for research working groups. '
                    .'Feedback on which comparisons are clinically meaningful is very welcome.</p>'
                    .'<p>This release marks a major step from a pure import repository towards an analysis platform that supports quality assurance and collaborative research questions.</p>'
                    .'<p>Documentation for the explorer will be expanded in the FAQ over the coming weeks.</p>',
                'category' => $platform,
                'tags' => [$tagBenchmarking, $tagStatistics],
                'status' => PostStatus::PUBLISHED,
            ],
            [
                'title' => 'He kept the old map folded in the back pocket of his coat.',
                'slug' => 'old-map-folded-in-coat-pocket',
                'content' => '<p>In early 2021, members of a regional emergency medicine working group began discussing how rarely EMS allocation data is analysed at scale. '
                    .'Individual hospitals held rich IVENA exports, but there was no infrastructure to combine them responsibly for collaborative research.</p>'
                    .'<p>The idea behind Collaborative IVENA Statistics grew out of those conversations: a shared platform where participating hospitals retain control over their own data while contributing to anonymised aggregate analyses.</p>'
                    .'<p>Initial prototypes focused on import mechanics and reference data alignment across sites with different IVENA configuration histories. '
                    .'Even simple questions, such as comparing urgency distributions between urban and rural catchment areas, required substantial normalisation work.</p>'
                    .'<p>Today the project spans multiple hospitals and continues to be shaped by clinicians, coordinators, and data staff who use the platform in parallel with their daily operations.</p>'
                    .'<p>This draft post will be expanded with a fuller project timeline once the public about page is updated.</p>',
                'category' => $research,
                'tags' => [$tagCollaboration],
                'status' => PostStatus::DRAFT,
            ],
        ];

        foreach ($posts as $postData) {
            $post = PostFactory::createOne([
                'title' => $postData['title'],
                'slug' => $postData['slug'],
                'content' => $postData['content'],
                'category' => $postData['category'],
                'tags' => $postData['tags'],
                'status' => $postData['status'],
                'publishedAt' => new \DateTimeImmutable('-'.random_int(1, 90).' days'),
                'createdBy' => UserFactory::random(),
            ]);

            if (PostStatus::PUBLISHED === $postData['status']) {
                PostCommentFactory::createOne([
                    'post' => $post,
                    'content' => 'Thanks for the update — great to see steady progress on the platform.',
                    'author' => UserFactory::random(),
                ]);
            }
        }
    }
}
