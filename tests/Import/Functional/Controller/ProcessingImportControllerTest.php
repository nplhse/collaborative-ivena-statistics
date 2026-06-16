<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ProcessingImportControllerTest extends WebTestCase
{
    use HasBrowser;
    use Factories;

    public function testProcessingPageShowsStepsForPendingImport(): void
    {
        [$owner, $importId] = $this->createImportForOwner(ImportStatus::PENDING);

        $this->browser()
            ->actingAs($owner)
            ->visit(\sprintf('/import/%d/processing', $importId))
            ->assertSuccessful()
            ->assertSeeElement('[data-import-status-target="importMeta"]')
            ->assertSee('Processing Test Import')
            ->assertSee('Your file is being processed in the background')
            ->assertSee('St. Test Hospital')
            ->assertSeeElement('.import-processing-hero')
            ->assertSeeElement('ul.steps.steps-vertical')
            ->assertSee('Processing data')
            ->assertSeeElement('[data-controller="import-status"]')
            ->assertSeeElement('[data-import-status-start-polling-value="true"]')
            ->assertSeeElement('[data-step-key="processing"].step-item.active')
            ->assertSeeElement('[data-import-status-target="detailLink"].d-none');
    }

    public function testProcessingPageShowsDetailButtonForCompletedImport(): void
    {
        [$owner, $importId] = $this->createImportForOwner(ImportStatus::COMPLETED);

        $this->browser()
            ->actingAs($owner)
            ->visit(\sprintf('/import/%d/processing', $importId))
            ->assertSuccessful()
            ->assertSee('Processing Test Import')
            ->assertSee('Finished')
            ->assertSee('The import finished successfully.')
            ->assertSeeElement('[data-import-status-start-polling-value="false"]')
            ->assertSeeElement('[data-step-key="result"].step-item.active')
            ->assertSeeElement('ul.steps-green')
            ->assertSeeElement('[data-import-status-target="detailLink"]:not(.d-none)')
            ->assertSee('View import details');
    }

    public function testForeignImportProcessingIsNotAccessible(): void
    {
        $owner = UserFactory::createOne([
            'username' => 'owner-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $intruder = UserFactory::createOne([
            'username' => 'intruder-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $import = ImportFactory::createOne([
            'name' => 'Protected Import',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'filePath' => '/tmp/protected.csv',
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 12,
            'rowCount' => 5,
            'runCount' => 0,
            'runTime' => 0,
            'createdBy' => $createdBy,
        ]);

        $this->browser()
            ->actingAs($intruder)
            ->visit(\sprintf('/import/%d/processing', $import->getId()))
            ->assertStatus(403);
    }

    /**
     * @return array{0:User,1:int}
     */
    private function createImportForOwner(ImportStatus $status): array
    {
        $owner = UserFactory::createOne([
            'username' => 'owner-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);

        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $import = ImportFactory::createOne([
            'name' => 'Processing Test Import',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => $status,
            'filePath' => '/tmp/processing-test.csv',
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 100,
            'rowCount' => 10,
            'runCount' => 0,
            'runTime' => 0,
            'createdBy' => $createdBy,
        ]);

        return [$owner->_real(), (int) $import->getId()];
    }
}
