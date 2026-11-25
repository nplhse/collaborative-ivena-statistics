<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

/**
 * A step on a data extract request was refused. The reason is a translation key in the `research` domain.
 */
final class DataRequestRefused extends \RuntimeException
{
    public const string NOT_OWN = 'data_request.refused.not_own';
    public const string NOT_EDITABLE = 'data_request.refused.not_editable';
    public const string TRANSITION_BLOCKED = 'data_request.refused.transition_blocked';
    public const string CHANGED_MEANWHILE = 'data_request.refused.changed_meanwhile';

    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
