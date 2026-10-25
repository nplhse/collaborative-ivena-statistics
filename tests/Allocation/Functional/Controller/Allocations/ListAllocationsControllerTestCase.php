<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
abstract class ListAllocationsControllerTestCase extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    protected function seedDependencies(): void
    {
        UserFactory::createOne(['username' => 'area-user']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        ImportFactory::createOne(['name' => 'Test Import']);
        $this->seedLookupDependencies();
    }

    protected function seedLookupDependencies(): void
    {
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        IndicationRawFactory::createOne(['name' => 'Test Indication']);
        IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
    }

    /**
     * @return list<string>
     */
    protected function extractAllocationIds(Crawler $crawler): array
    {
        $ids = [];
        foreach ($crawler->filter('td .btn-actions a') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $href = $node->getAttribute('href');
            if (preg_match('/\/explore\/allocation\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/', $href, $matches)) {
                $ids[] = $matches[1];
            }
        }

        return $ids;
    }

    protected function findNextPageHref(Crawler $crawler): ?string
    {
        return $this->findPaginationHref($crawler, ['Next', 'Weiter']);
    }

    protected function findPreviousPageHref(Crawler $crawler): ?string
    {
        return $this->findPaginationHref($crawler, ['Previous', 'Zurück']);
    }

    /**
     * Returns the href of an enabled pagination link; disabled links point to "#".
     *
     * @param list<string> $labels
     */
    private function findPaginationHref(Crawler $crawler, array $labels): ?string
    {
        $links = $crawler->filter('ul.pagination a.page-link');
        foreach ($links as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            $text = trim($link->textContent);
            $href = $link->getAttribute('href');
            if ('' === $href || '#' === $href) {
                continue;
            }
            foreach ($labels as $label) {
                if (str_contains($text, $label)) {
                    return $href;
                }
            }
        }

        return null;
    }
}
