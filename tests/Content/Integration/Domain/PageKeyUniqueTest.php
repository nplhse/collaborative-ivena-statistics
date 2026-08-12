<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Domain;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageKeyUniqueTest extends KernelTestCase
{
    use Factories;

    private ValidatorInterface $validator;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSamePageKeyCannotBeAssignedTwice(): void
    {
        PageFactory::createOne([
            'slug' => 'terms-first',
            'path' => '/legal/terms-first',
            'key' => PageKey::Terms,
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        $duplicate = new Page();
        $duplicate
            ->setKey(PageKey::Terms)
            ->setVisibility(Page::VISIBILITY_PUBLIC);

        $duplicate->addTranslation(
            new PageTranslation()
                ->setLocale('en')
                ->setTitle('Second terms')
                ->setSlug('terms-second')
                ->setPath('/legal/terms-second')
                ->setStatus(PageTranslation::STATUS_PUBLISHED)
                ->setContent([
                    [
                        'type' => 'richtext',
                        'data' => ['html' => '<p>x</p>'],
                    ],
                ]),
        );

        $this->entityManager->persist($duplicate);
        $violations = $this->validator->validate($duplicate);

        self::assertGreaterThan(0, $violations->count());
    }

    public function testPageCanExistWithKey(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'privacy-page',
            'path' => '/legal/privacy-policy',
            'key' => PageKey::Privacy,
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        self::assertSame(PageKey::Privacy, $page->getKey());
    }
}
