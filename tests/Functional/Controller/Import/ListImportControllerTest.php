<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Import;

use App\Entity\User;
use App\Enum\ImportStatus;
use App\Enum\ImportType;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\ImportFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ListImportControllerTest extends WebTestCase
{
    use HasBrowser;
    use Factories;
    use ResetDatabase;

    public function testTableWithResultsIsShown(): void
    {
        [$owner] = $this->seedImportsWithFactory(10);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import')
            ->assertSuccessful()
            ->assertSeeElement('table.table thead')
            ->assertSeeElement('table.table tbody')
            ->use(function (Crawler $crawler): void {
                $rows = $crawler->filter('table.table tbody tr');
                self::assertCount(10, $rows, 'We should see 10 rows of results.');

                $firstTds = $rows->eq(0)->filter('td');
                self::assertGreaterThanOrEqual(6, $firstTds->count());
                $nameText = \trim($firstTds->eq(1)->text(''));
                self::assertNotEmpty($nameText);
            })
            ->assertSeeElement('#result-count')
            ->use(function (Crawler $crawler): void {
                $txt = \preg_replace('/\s+/', ' ', $crawler->filter('#result-count')->text(''));
                self::assertStringContainsString('1', $txt);
                self::assertStringContainsString('10', $txt);
            });
    }

    public function testTableCanBeSortedByNameDesc(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        ImportFactory::createOne([
            'name' => 'ACME Hospital Import',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'filePath' => '/tmp/acme.csv',
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 12,
            'rowCount' => 5,
            'rowsPassed' => 4,
            'rowsRejected' => 1,
            'runCount' => 0,
            'runTime' => 0,
            'createdBy' => $createdBy,
        ]);

        ImportFactory::createOne([
            'name' => 'XYZ Hospital Import',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'filePath' => '/tmp/xyz.csv',
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 12,
            'rowCount' => 5,
            'rowsPassed' => 4,
            'rowsRejected' => 1,
            'runCount' => 0,
            'runTime' => 0,
            'createdBy' => $createdBy,
        ]);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import?sortBy=name&orderBy=desc')
            ->assertSuccessful()
            ->use(function (Crawler $crawler): void {
                $rows = $crawler->filter('table.table tbody tr');
                self::assertGreaterThanOrEqual(2, $rows->count());
                $firstName = \trim($rows->eq(0)->filter('td')->eq(1)->text(''));
                self::assertSame('XYZ Hospital Import', $firstName);
            });
    }

    public function testTableCanBePaginated(): void
    {
        [$owner] = $this->seedImportsWithFactory(35);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import?page=2')
            ->assertSuccessful()
            ->use(function (Crawler $crawler): void {
                $rows = $crawler->filter('table.table tbody tr');
                self::assertCount(10, $rows, 'We should see 10 rows of results.');

                $txt = \preg_replace('/\s+/', ' ', $crawler->filter('#result-count')->text(''));
                self::assertStringContainsString('26', $txt);
                self::assertStringContainsString('35', $txt);
            });
    }

    /**
     * @return array{
     *      0: \App\Entity\User&\Zenstruck\Foundry\Persistence\Proxy<\App\Entity\User>,
     *      1: \App\Entity\Hospital&\Zenstruck\Foundry\Persistence\Proxy<\App\Entity\Hospital>,
     *      2: \App\Entity\User&\Zenstruck\Foundry\Persistence\Proxy<\App\Entity\User>
     *  }
     */
    private function seedBaseActors(): array
    {
        $owner = UserFactory::createOne(['username' => 'owner-'.\bin2hex(random_bytes(3))]);
        $createdBy = UserFactory::findOrCreate(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);

        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        return [$owner, $hospital, $createdBy];
    }

    /**
     * @return array{0:User}
     */
    private function seedImportsWithFactory(int $count): array
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        if ($count > 0) {
            ImportFactory::createMany($count, function (int $i) use ($hospital, $createdBy) {
                return [
                    'name' => \sprintf('Import %02d', $i + 1),
                    'hospital' => $hospital,
                    'type' => ImportType::ALLOCATION,
                    'status' => ImportStatus::PENDING,
                    'filePath' => \sprintf('/tmp/import_%02d.csv', $i + 1),
                    'fileExtension' => 'csv',
                    'fileMimeType' => 'text/csv',
                    'fileSize' => 100 + $i,
                    'fileChecksum' => \substr(\sha1((string) $i), 0, 12),
                    'rowCount' => 5,
                    'rowsPassed' => 4,
                    'rowsRejected' => 1,
                    'runCount' => 0,
                    'runTime' => 0,
                    'createdBy' => $createdBy,
                ];
            });
        }

        return [$owner];
    }
}
