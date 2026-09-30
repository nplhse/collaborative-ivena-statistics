<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Application;

use App\Shared\Application\DataTable\DataTablePreferenceSchema;
use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Shared\Domain\Entity\DataTablePreference;
use App\Shared\Infrastructure\Repository\DataTablePreferenceRepository;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureEventTableColumns;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DataTablePreferenceServiceTest extends KernelTestCase
{
    use Factories;

    public function testPreferencesAreIsolatedByUserAndTableKey(): void
    {
        self::bootKernel();
        $first = UserFactory::createOne(['username' => 'table-pref-first']);
        $second = UserFactory::createOne(['username' => 'table-pref-second']);
        $repository = self::getContainer()->get(DataTablePreferenceRepository::class);
        $service = self::getContainer()->get(DataTablePreferenceService::class);
        $definition = self::getContainer()->get(ClosureEventTableColumns::class);
        $schema = $definition->preferenceSchema();

        $repository->save(new DataTablePreference($first, $schema->key, [
            'visibleColumns' => ['startsAt', 'event', 'actualMinutes', 'reasons'],
            'columnOrder' => ['reasons', 'startsAt', 'event', 'actualMinutes'],
            'pageSize' => 50,
        ]));
        $repository->save(new DataTablePreference($second, $schema->key, [
            'visibleColumns' => ['startsAt', 'event', 'actualMinutes', 'hospital'],
            'columnOrder' => ['hospital', 'event', 'startsAt', 'actualMinutes'],
            'pageSize' => 100,
        ]));
        $otherSchema = new DataTablePreferenceSchema(
            'test.other_table',
            ['alpha', 'beta'],
            ['alpha'],
            ['alpha'],
        );
        $repository->save(new DataTablePreference($first, $otherSchema->key, [
            'visibleColumns' => ['alpha', 'beta'],
            'columnOrder' => ['beta', 'alpha'],
            'pageSize' => 25,
        ]));

        $firstState = $service->resolve($first, $schema);
        $secondState = $service->resolve($second, $schema);
        self::assertSame(50, $firstState->pageSize);
        self::assertSame('reasons', $firstState->columnOrder[0]);
        self::assertSame(100, $secondState->pageSize);
        self::assertSame('hospital', $secondState->columnOrder[0]);
        self::assertSame(['beta', 'alpha'], $service->resolve($first, $otherSchema)->columnOrder);
    }

    public function testStoredPreferencesAreReconciledWithAnEvolvedDefinition(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'table-pref-evolution']);
        $repository = self::getContainer()->get(DataTablePreferenceRepository::class);
        $service = self::getContainer()->get(DataTablePreferenceService::class);
        $schema = new DataTablePreferenceSchema(
            'test.evolved_table',
            ['required', 'known', 'new'],
            ['required', 'new'],
            ['required'],
        );
        $repository->save(new DataTablePreference($user, $schema->key, [
            'visibleColumns' => ['removed', 'known'],
            'columnOrder' => ['removed', 'known', 'required'],
            'pageSize' => 999,
        ]));

        $state = $service->resolve($user, $schema);

        self::assertSame(['known', 'required', 'new'], $state->columnOrder);
        self::assertSame(['known', 'new', 'required'], $state->visibleColumns);
        self::assertSame(25, $state->pageSize);
    }

    public function testSaveAndResetUseRegisteredDefinition(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'table-pref-reset']);
        $service = self::getContainer()->get(DataTablePreferenceService::class);
        $repository = self::getContainer()->get(DataTablePreferenceRepository::class);
        $schema = self::getContainer()->get(ClosureEventTableColumns::class)->preferenceSchema();

        $service->save($user, $schema->key, [
            'visibleColumns' => ['reasons'],
            'columnOrder' => ['reasons', 'unknown', 'startsAt'],
            'pageSize' => 50,
        ]);
        $saved = $service->resolve($user, $schema);
        self::assertSame('reasons', $saved->columnOrder[0]);
        self::assertContains('startsAt', $saved->visibleColumns);
        self::assertNotNull($repository->findForUserAndTable($user, $schema->key));

        $service->reset($user, $schema->key);
        self::assertNull($repository->findForUserAndTable($user, $schema->key));
        self::assertSame($schema->defaults()->toArray(), $service->resolve($user, $schema)->toArray());
    }

    public function testResetColumnsRestoresDefaultLayoutAndKeepsPageSize(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'table-pref-reset-columns']);
        $service = self::getContainer()->get(DataTablePreferenceService::class);
        $schema = self::getContainer()->get(ClosureEventTableColumns::class)->preferenceSchema();

        $service->save($user, $schema->key, [
            'visibleColumns' => ['reasons'],
            'columnOrder' => ['reasons', 'startsAt'],
            'pageSize' => 50,
        ]);
        $service->resetColumns($user, $schema->key);
        $state = $service->resolve($user, $schema);

        self::assertSame($schema->defaults()->columnOrder, $state->columnOrder);
        self::assertSame($schema->defaults()->visibleColumns, $state->visibleColumns);
        self::assertSame(50, $state->pageSize);
    }

    public function testResetSortRestoresDefaultPageSizeAndKeepsColumns(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'table-pref-reset-sort']);
        $service = self::getContainer()->get(DataTablePreferenceService::class);
        $schema = self::getContainer()->get(ClosureEventTableColumns::class)->preferenceSchema();

        $service->save($user, $schema->key, [
            'visibleColumns' => ['reasons'],
            'columnOrder' => ['reasons', 'startsAt'],
            'pageSize' => 50,
        ]);
        $saved = $service->resolve($user, $schema);

        $service->resetSort($user, $schema->key);
        $state = $service->resolve($user, $schema);

        self::assertSame($saved->columnOrder, $state->columnOrder);
        self::assertSame($saved->visibleColumns, $state->visibleColumns);
        self::assertSame($schema->defaultPageSize, $state->pageSize);
    }
}
