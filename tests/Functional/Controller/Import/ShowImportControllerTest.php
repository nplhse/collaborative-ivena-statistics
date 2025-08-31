<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Import;

use App\Entity\Import;
use App\Entity\User;
use App\Enum\ImportStatus;
use App\Enum\ImportType;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ShowImportControllerTest extends WebTestCase
{
    use HasBrowser;
    use Factories;
    use ResetDatabase;

    public function testShowDisplaysImportDetails(): void
    {
        [$owner, $importId, $name, $hospitalName] = $this->createImportWithRelations();

        $this->browser()
            ->actingAs($owner)
            ->visit(\sprintf('/import/%d', $importId))
            ->assertSuccessful()

            ->assertSeeElement('#import-id')
            ->assertSee('#'.$importId)
            ->assertSee($name)

            ->assertSee($hospitalName)

            ->assertSeeIn('.datagrid', 'CSV')
            ->assertSeeIn('.datagrid', 'Mime Type')
            ->assertSeeIn('.datagrid', 'KB')

            ->assertSee('PENDING')
            ->assertSee('ALLOCATION')
            ->assertSee('0 ms')
            ->assertSee('4')
            ->assertSee('1');
    }

    /**
     * @return array{0:User,1:int,2:string,3:string} [Owner, ImportId, ImportName, HospitalName]
     */
    private function createImportWithRelations(): array
    {
        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);

        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $name = 'Test Allocations';
        $hospitalName = $hospital->getName();
        $target = 'dummy/path';

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var User $freshOwner */
        $freshOwner = $em->getRepository(User::class)->find($owner->getId());
        self::assertNotNull($freshOwner);

        $import = new Import()
            ->setName($name)
            ->setHospital($hospital->_real())
            ->setCreatedBy($freshOwner)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($target)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(\filesize($target) ?: 0)
            ->setFileChecksum('abc123checksum')
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(5)
            ->setRowsPassed(4)
            ->setRowsRejected(1);

        $em->persist($import);
        $em->flush();

        return [$freshOwner, (int) $import->getId(), $name, $hospitalName];
    }
}
