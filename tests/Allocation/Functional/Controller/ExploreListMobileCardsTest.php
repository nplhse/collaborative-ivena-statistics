<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The smaller Explore lists use the DataTable card layout; the breakpoint follows their minimum width.
 */
#[ResetDatabase]
final class ExploreListMobileCardsTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lists(): iterable
    {
        yield 'mci cases' => ['/explore/mci_case', 'md'];
        yield 'dispatch areas' => ['/explore/dispatch_area', 'md'];
        yield 'specialities' => ['/explore/speciality', 'md'];
        yield 'departments' => ['/explore/department', 'sm'];
        yield 'assignments' => ['/explore/assignment', 'sm'];
        yield 'indication groups' => ['/explore/indication_group', 'sm'];
        yield 'infections' => ['/explore/infection', 'sm'];
        yield 'secondary transports' => ['/explore/secondary_transport', 'sm'];
        yield 'occasions' => ['/explore/occasion', 'sm'];
        yield 'states' => ['/explore/state', 'sm'];
    }

    #[DataProvider('lists')]
    public function testListUsesCardsWithItsBreakpoint(string $url, string $breakpoint): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('[data-testid="data-table-cards"].data-table-cards--%s table[role="table"]', $breakpoint));
    }
}
