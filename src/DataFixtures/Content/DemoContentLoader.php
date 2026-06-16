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
        PostTagFactory::createOne(['name' => 'Release', 'slug' => 'release']);
        PostTagFactory::createOne(['name' => 'Statistics', 'slug' => 'statistics']);

        $posts = [
            [
                'title' => 'Devlog #1: Getting started',
                'slug' => 'devlog-1-getting-started',
                'content' => '<p>Welcome to the first devlog for Collaborative IVENA Statistics. This alpha release is a proof of concept with known limitations in email delivery, import scope, and statistics performance.</p>',
                'category' => $devlog,
                'status' => PostStatus::PUBLISHED,
            ],
            [
                'title' => 'Devlog #3: First statistics optimisations',
                'slug' => 'devlog-3-statistics-optimisations',
                'content' => '<p>We merged redundant database queries and introduced materialized views for overview metrics. Query time on the overview dashboard dropped by roughly 80%.</p>',
                'category' => $devlog,
                'status' => PostStatus::PUBLISHED,
            ],
            [
                'title' => 'Devlog #5: Import data quality improvements',
                'slug' => 'devlog-5-import-data-quality',
                'content' => '<p>Several normalisation fixes improve dispatch area matching, assessment handling, and department mapping during import.</p>',
                'category' => $devlog,
                'status' => PostStatus::PUBLISHED,
            ],
            [
                'title' => 'Devlog #10: Benchmarking arrives',
                'slug' => 'devlog-10-benchmarking',
                'content' => '<p>The new statistics explorer introduces configurable analyses and first benchmarking views to compare scopes and time periods.</p>',
                'category' => $devlog,
                'status' => PostStatus::PUBLISHED,
            ],
            [
                'title' => 'The story behind this project',
                'slug' => 'project-origin-story',
                'content' => '<p>In early 2021, members of the DGINA Hesse working group started building a collaborative platform to merge IVENA allocation exports from multiple hospitals.</p>',
                'category' => $news,
                'status' => PostStatus::DRAFT,
            ],
        ];

        foreach ($posts as $postData) {
            $post = PostFactory::createOne([
                'title' => $postData['title'],
                'slug' => $postData['slug'],
                'content' => $postData['content'],
                'category' => $postData['category'],
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
