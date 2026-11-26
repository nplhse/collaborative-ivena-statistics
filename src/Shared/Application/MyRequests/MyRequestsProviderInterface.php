<?php

declare(strict_types=1);

namespace App\Shared\Application\MyRequests;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for the "My requests" page (#1635): each context lists the requests the signed-in user made there,
 * such as hospital assignment requests (Allocation) and data extract requests (Research). Providers read the current
 * user themselves and return null when the user may not make that kind of request. The page is the only caller, so
 * providers may query the database.
 */
#[AutoconfigureTag(self::TAG)]
interface MyRequestsProviderInterface
{
    public const string TAG = 'shared.my_requests';

    public function section(): ?MyRequestsSection;
}
