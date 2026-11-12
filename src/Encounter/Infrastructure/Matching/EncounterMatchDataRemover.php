<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Matching;

use App\Encounter\Application\Matching\MatchScope;
use App\Encounter\Application\Message\MatchEncountersMessage;
use App\Encounter\Infrastructure\Query\MatchTriggerQuery;
use App\Encounter\Infrastructure\Repository\EncounterMatchRepository;
use App\Import\Application\Contracts\ImportDataRemoverInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Deletion or reprocess of an allocation or encounter import (ADR 028, ADR 029): removes the links,
 * proposals and decisions of its allocations and visits, and queues a matcher rerun for the hospital-days
 * this frees, so the remaining allocations and visits can be linked anew.
 *
 * Runs before EncounterImportDataRemover (priority), while the import's visits still exist. The rerun
 * leaves the import's rows out, so it is correct even when it starts before the deletion commits; the
 * delay lets the deleting transaction finish first in production.
 *
 * Manual decisions of a deleted allocation or visit are lost with it; a reprocessed allocation import
 * gets new allocation ids and is matched again automatically.
 *
 * @psalm-suppress UnusedClass Tagged via ImportDataRemoverInterface.
 */
#[AsTaggedItem(priority: 10)]
final readonly class EncounterMatchDataRemover implements ImportDataRemoverInterface
{
    private const int RERUN_DELAY_MS = 30_000;

    public function __construct(
        private EncounterMatchRepository $matches,
        private MatchTriggerQuery $triggers,
        private MessageBusInterface $bus,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'encounter_matches';
    }

    #[\Override]
    public function removeForImport(int $importId): int
    {
        $affected = $this->triggers->importDays($importId);
        if (null === $affected) {
            return 0;
        }

        $deleted = $this->matches->deleteForImport($importId);

        $days = [...$affected['days'], ...MatchScope::aroundVisitDays($affected['hospitalId'], $affected['visitDays'])->days];
        if ([] !== $days) {
            $scope = new MatchScope($affected['hospitalId'], $days, $importId);
            $this->bus->dispatch(new MatchEncountersMessage($scope->hospitalId, $scope->days, $importId), [new DelayStamp(self::RERUN_DELAY_MS)]);
        }

        return $deleted;
    }
}
