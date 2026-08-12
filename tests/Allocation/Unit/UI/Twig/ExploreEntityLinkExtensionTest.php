<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\UI\Twig;

use App\Allocation\Application\Explore\ExploreShowUrlResolver;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\UI\Twig\ExploreEntityLinkExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class ExploreEntityLinkExtensionTest extends TestCase
{
    private const string PUBLIC_ID = '11111111-1111-4111-8111-111111111111';

    public function testExploreEntityUrlDelegatesToResolver(): void
    {
        $department = $this->department('Cardiology');
        $extension = $this->extension();

        self::assertSame(
            '/explore/department/'.self::PUBLIC_ID,
            $extension->exploreEntityUrl($department),
        );
    }

    public function testExploreEntityLinkRendersAnchorForResolvableEntity(): void
    {
        $department = $this->department('Cardiology');
        $extension = $this->extension();

        self::assertSame(
            '<a href="/explore/department/'.self::PUBLIC_ID.'">Cardiology</a>',
            $extension->exploreEntityLink($department),
        );
    }

    public function testExploreEntityLinkAppliesClassAndCustomLabel(): void
    {
        $department = $this->department('Cardiology');
        $extension = $this->extension();

        self::assertSame(
            '<a href="/explore/department/'.self::PUBLIC_ID.'" class="link-secondary">Dept</a>',
            $extension->exploreEntityLink($department, [
                'label' => 'Dept',
                'class' => 'link-secondary',
            ]),
        );
    }

    public function testExploreEntityLinkEscapesLabelAndClass(): void
    {
        $department = $this->department('A <b>bold</b> name');
        $extension = $this->extension();

        self::assertSame(
            '<a href="/explore/department/'.self::PUBLIC_ID.'" class="x&quot;onclick">A &lt;b&gt;bold&lt;/b&gt; name</a>',
            $extension->exploreEntityLink($department, ['class' => 'x"onclick']),
        );
    }

    public function testExploreEntityLinkRendersPlainTextWhenEntityHasNoDestination(): void
    {
        $raw = new IndicationRaw();
        $raw->setName('Raw indication');
        $raw->setPublicId(Uuid::fromString(self::PUBLIC_ID));

        $extension = $this->extension();

        self::assertSame('Raw indication', $extension->exploreEntityLink($raw));
        self::assertNull($extension->exploreEntityUrl($raw));
    }

    public function testExploreEntityLinkRendersEmptyFallbackForNullEntity(): void
    {
        $extension = $this->extension();

        self::assertSame('—', $extension->exploreEntityLink(null));
        self::assertSame('n/a', $extension->exploreEntityLink(null, ['empty' => 'n/a']));
    }

    public function testExploreEntityLinkRendersPlainTextWhenPublicIdIsMissing(): void
    {
        $department = new Department();
        $department->setName('Unpublished');

        $extension = $this->extension();

        self::assertSame('Unpublished', $extension->exploreEntityLink($department));
    }

    private function extension(): ExploreEntityLinkExtension
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => match ($route) {
                'app_explore_department_show' => '/explore/department/'.($params['publicId'] ?? ''),
                default => throw new \InvalidArgumentException($route),
            },
        );

        return new ExploreEntityLinkExtension(new ExploreShowUrlResolver($urlGenerator));
    }

    private function department(string $name): Department
    {
        $department = new Department();
        $department->setName($name);
        $department->setPublicId(Uuid::fromString(self::PUBLIC_ID));

        return $department;
    }
}
