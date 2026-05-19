<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PagePathResolver;
use App\Content\Domain\Entity\Page;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PagePathSynchronizationValidationTest extends KernelTestCase
{
    public function testPathNotBlankPassesAfterSynchronize(): void
    {
        self::bootKernel();

        $resolver = self::getContainer()->get(PagePathResolver::class);
        $validator = self::getContainer()->get(ValidatorInterface::class);

        $page = new Page();
        $page
            ->setTitle('Test 123')
            ->setSlug('test-123')
            ->setStatus(Page::STATUS_DRAFT)
            ->setVisibility(Page::VISIBILITY_PUBLIC);

        $violationsBefore = $validator->validate($page);
        self::assertNotEmpty($violationsBefore);
        self::assertSame('path', (string) $violationsBefore->get(0)->getPropertyPath());

        $resolver->synchronize($page);

        $violationsAfter = $validator->validate($page);
        self::assertCount(0, $violationsAfter);
        self::assertSame('/test-123', $page->getPath());
    }
}
