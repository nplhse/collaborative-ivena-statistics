<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Domain;

use App\Content\Domain\Entity\Page;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class PageHierarchyValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testParentMustNotBeSelf(): void
    {
        $id = bin2hex(random_bytes(4));
        $page = $this->makePage('p-self-'.$id, '/p-self-'.$id);
        $page->setParent($page);

        $violations = $this->validator->validate($page);
        $violation = $this->firstHierarchyViolation($violations, 'parent');
        self::assertNotNull($violation, 'Expected violation at parent: '.$this->formatViolations($violations));
        self::assertNotSame('', trim((string) $violation->getMessage()));
    }

    public function testParentChainMustNotFormCycle(): void
    {
        $id = bin2hex(random_bytes(4));
        $a = $this->makePage('c-a-'.$id, '/c-a-'.$id);
        $b = $this->makePage('c-b-'.$id, '/c-b-'.$id);
        $c = $this->makePage('c-c-'.$id, '/c-c-'.$id);

        $a->setParent($b);
        $b->setParent($c);
        $c->setParent($a);

        $violations = $this->validator->validate($a);
        $violation = $this->firstHierarchyViolation($violations, 'parent');
        self::assertNotNull($violation, 'Expected violation at parent: '.$this->formatViolations($violations));
        self::assertNotSame('', trim((string) $violation->getMessage()));
    }

    /**
     * @param iterable<ConstraintViolationInterface> $violations
     */
    private function firstHierarchyViolation(iterable $violations, string $path): ?ConstraintViolationInterface
    {
        foreach ($violations as $v) {
            if ($v->getPropertyPath() === $path) {
                return $v;
            }
        }

        return null;
    }

    private function makePage(string $slug, string $path): Page
    {
        $page = new Page();
        $page
            ->setTitle('Titel '.$slug)
            ->setSlug($slug)
            ->setPath($path)
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setVisibility(Page::VISIBILITY_PUBLIC)
            ->setContent([
                [
                    'type' => 'richtext',
                    'data' => ['html' => '<p>x</p>'],
                ],
            ])
        ;

        return $page;
    }

    /**
     * @param iterable<ConstraintViolationInterface> $violations
     */
    private function formatViolations(iterable $violations): string
    {
        $parts = [];
        foreach ($violations as $v) {
            $parts[] = $v->getPropertyPath().': '.$v->getMessage();
        }

        return implode('; ', $parts);
    }
}
