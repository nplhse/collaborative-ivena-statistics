<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Request;

final class ListAllocationsEmptyStateTest extends ListAllocationsControllerTestCase
{
    public function testEmptyListWithoutImportPermissionExplainsAssociation(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(Request::METHOD_GET, '/explore/allocation');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-title', 'No allocations yet');
        self::assertSelectorTextContains('.empty-subtitle', 'Import access is granted per hospital');
        self::assertSelectorNotExists('a[href="/import/new"]');
        self::assertSelectorExists('.empty-action a[href="/"][target="_top"]');
    }

    public function testEmptyListOffersImportWhenTheUserOwnsAHospital(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne([
            'username' => 'alloc-owner-'.bin2hex(random_bytes(3)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $state = StateFactory::createOne(['name' => 'EmptyAllocState']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'EmptyAllocDispatch', 'state' => $state]);
        HospitalFactory::createOne([
            'name' => 'Empty Alloc Hospital',
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/explore/allocation');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-subtitle', 'Import your first IVENA dataset');
        self::assertSelectorExists('.empty-action a[href="/import/new"][target="_top"]');
    }

    public function testFilteredEmptyListOffersReset(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(Request::METHOD_GET, '/explore/allocation?urgency=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-title', 'No results for the current filters');
        self::assertSelectorTextContains('.empty-action', 'Reset filters');
        self::assertSelectorExists('.empty-action a:not([target])');
        self::assertSelectorNotExists('.empty-action a[href="/import/new"]');
    }
}
