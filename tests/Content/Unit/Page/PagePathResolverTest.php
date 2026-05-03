<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PagePathResolver;
use App\Content\Domain\Entity\Page;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class PagePathResolverTest extends TestCase
{
    public function testBuildPathForRootAndChildPage(): void
    {
        $resolver = new PagePathResolver(new AsciiSlugger());

        $root = new Page()->setSlug('Produkte');
        $root->setPath($resolver->buildPath($root));

        $child = new Page()
            ->setSlug('Hosting')
            ->setParent($root);

        self::assertSame('/produkte', $root->getPath());
        self::assertSame('/produkte/hosting', $resolver->buildPath($child));
    }

    public function testThrowsOnCycle(): void
    {
        $resolver = new PagePathResolver(new AsciiSlugger());
        $first = new Page()->setSlug('first');
        $second = new Page()->setSlug('second');

        $first->setParent($second);
        $second->setParent($first);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->buildPath($first);
    }
}
