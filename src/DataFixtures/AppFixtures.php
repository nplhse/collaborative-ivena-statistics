<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\MciCaseFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PostCategoryFactory;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Factory\PostTagFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    #[\Override]
    public function load(ObjectManager $manager): void
    {
        UserFactory::createMany(5);

        StateFactory::createMany(3);
        DispatchAreaFactory::createMany(49);
        $dispatchArea = DispatchAreaFactory::createOne(
            ['name' => 'Test Area'])
        ;

        HospitalFactory::createMany(9);
        $hospital = HospitalFactory::createOne([
            'name' => 'Test Hospital',
            'dispatchArea' => $dispatchArea,
        ]);

        ImportFactory::createMany(14);
        ImportFactory::createOne([
            'name' => 'Test Import',
            'hospital' => $hospital,
            'status' => ImportStatus::PENDING,
        ]
        );

        DepartmentFactory::createMany(5);
        SpecialityFactory::createMany(10);

        AssignmentFactory::createMany(5);
        InfectionFactory::createMany(10);
        OccasionFactory::createMany(5);

        IndicationRawFactory::createMany(25);
        IndicationNormalizedFactory::createMany(20);
        $faker = \Faker\Factory::create();
        AllocationFactory::createMany(random_int(250, 500), static function (int $_) use ($faker): array {
            $createdAt = \DateTimeImmutable::createFromMutable(
                $faker->dateTimeBetween('-12 months', 'now'),
            );

            return [
                'createdAt' => $createdAt,
                'arrivalAt' => $createdAt->add(new \DateInterval('PT'.random_int(5, 120).'M')),
            ];
        });
        MciCaseFactory::createMany(8);

        PostCategoryFactory::createOne();
        PostCategoryFactory::createOne();

        PostTagFactory::createOne();
        PostTagFactory::createOne();
        PostTagFactory::createOne();

        PostFactory::createMany(15);

        PostCommentFactory::createMany(6);

        $aboutPage = PageFactory::createOne([
            'title' => 'About us',
            'slug' => 'about',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 10,
            'content' => [
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => [
                        'text' => 'About our platform',
                        'level' => 'h2',
                        'align' => 'left',
                        'spacingBefore' => 'none',
                        'spacingAfter' => 'md',
                    ],
                ],
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>',
                    ],
                ],
                [
                    'type' => 'highlight',
                    'enabled' => true,
                    'data' => [
                        'variant' => 'info',
                        'title' => 'Did you know?',
                        'html' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>',
                        'iconMode' => 'auto',
                    ],
                ],
                [
                    'type' => 'quote',
                    'enabled' => true,
                    'data' => [
                        'text' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
                    ],
                ],
                [
                    'type' => 'accordion',
                    'enabled' => true,
                    'data' => [
                        'items' => [
                            [
                                'title' => 'What is Lorem Ipsum?',
                                'html' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>',
                                'openByDefault' => true,
                            ],
                            [
                                'title' => 'Why do we use it?',
                                'html' => '<p>It is a long established fact that a reader will be distracted.</p>',
                                'openByDefault' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        PageFactory::createOne([
            'title' => 'Contact',
            'slug' => 'kontakt',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 20,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<h2>Contact</h2><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p><p><strong>Email:</strong> hello@example.org</p>',
                    ],
                ],
                [
                    'type' => 'cta',
                    'enabled' => true,
                    'data' => [
                        'headline' => 'Get in touch now',
                        'buttonLabel' => 'Contact form',
                        'buttonUrl' => '/kontakt',
                    ],
                ],
            ],
        ]);

        $productsPage = PageFactory::createOne([
            'title' => 'Products',
            'slug' => 'produkte',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 30,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<h2>Our products</h2><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>',
                    ],
                ],
            ],
        ]);

        PageFactory::createOne([
            'title' => 'Hosting',
            'slug' => 'hosting',
            'parent' => $productsPage,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 10,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<h2>Hosting</h2><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt.</p>',
                    ],
                ],
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'src' => '/uploads/example-hosting.jpg',
                        'alt' => 'Server Rack',
                        'caption' => 'Lorem ipsum caption',
                        'size' => 'md',
                        'float' => 'left',
                    ],
                ],
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<p>Additional hosting details wrap around the floated image on larger screens. Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>',
                    ],
                ],
            ],
        ]);

        PageFactory::createOne([
            'title' => 'Consulting',
            'slug' => 'beratung',
            'parent' => $productsPage,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 20,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<h2>Consulting</h2><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>',
                    ],
                ],
            ],
        ]);

        PageFactory::createOne([
            'title' => 'Members area',
            'slug' => 'mitgliederbereich',
            'parent' => $aboutPage,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
            'sortOrder' => 30,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<h2>Internal area</h2><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Only signed-in users can access this page.</p>',
                    ],
                ],
            ],
        ]);

        PageFactory::createOne([
            'title' => 'Roadmap draft',
            'slug' => 'roadmap-entwurf',
            'status' => Page::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 99,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<h2>Draft</h2><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>',
                    ],
                ],
            ],
        ]);

        $manager->flush();
    }

    /**
     * @return list<class-string<FixtureInterface>>
     */
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}
