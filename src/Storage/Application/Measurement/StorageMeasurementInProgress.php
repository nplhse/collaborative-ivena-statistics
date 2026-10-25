<?php

declare(strict_types=1);

namespace App\Storage\Application\Measurement;

/**
 * Another process is measuring right now; a second walk at the same time would only double the load.
 */
final class StorageMeasurementInProgress extends \RuntimeException
{
}
