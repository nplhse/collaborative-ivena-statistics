<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\CaseFile;

use App\Allocation\Application\CaseFile\AllocationCaseSection;
use App\Allocation\Application\CaseFile\AllocationCaseSectionProviderInterface;
use App\Allocation\Application\CaseFile\AllocationCaseSectionRegistry;
use App\Allocation\Domain\Entity\Allocation;
use PHPUnit\Framework\TestCase;

final class AllocationCaseSectionRegistryTest extends TestCase
{
    public function testCollectsTheSectionsOfAllProvidersInOrder(): void
    {
        $first = $this->createStub(AllocationCaseSectionProviderInterface::class);
        $first->method('sectionsFor')->willReturn([new AllocationCaseSection('a', '@X/a.html.twig'), new AllocationCaseSection('b', '@X/b.html.twig')]);
        $none = $this->createStub(AllocationCaseSectionProviderInterface::class);
        $none->method('sectionsFor')->willReturn([]);
        $last = $this->createStub(AllocationCaseSectionProviderInterface::class);
        $last->method('sectionsFor')->willReturn([new AllocationCaseSection('c', '@Y/c.html.twig', ['value' => 1])]);

        $sections = new AllocationCaseSectionRegistry([$first, $none, $last])->sectionsFor(new Allocation());

        self::assertSame(['a', 'b', 'c'], array_map(static fn (AllocationCaseSection $section): string => $section->key, $sections));
        self::assertSame(['value' => 1], $sections[2]->context);
    }
}
