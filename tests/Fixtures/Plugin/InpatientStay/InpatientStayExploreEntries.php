<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay;

use App\Encounter\Contract\Display\ExploreEntry;
use App\Encounter\Contract\Display\ExploreEntryProviderInterface;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class InpatientStayExploreEntries implements ExploreEntryProviderInterface
{
    #[\Override]
    public function exploreEntries(): array
    {
        return [new ExploreEntry(
            key: 'inpatient-stays',
            title: new TranslatableMessage('inpatient_stay_test.explore.title', domain: 'inpatient_stay_test'),
            description: new TranslatableMessage('inpatient_stay_test.explore.description', domain: 'inpatient_stay_test'),
            icon: 'tabler:bed',
            route: 'app_explore_inpatient_stays',
        )];
    }
}
