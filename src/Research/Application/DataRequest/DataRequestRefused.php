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
    public const string NOT_ALLOWED = 'data_request.refused.not_allowed';
    public const string NO_OPEN_ROUND = 'data_request.refused.no_open_round';
    public const string NOT_ELIGIBLE = 'data_request.refused.not_eligible';
    public const string REASON_REQUIRED = 'data_request.refused.reason_required';
    public const string NO_ELIGIBLE_MEMBERS = 'data_request.refused.no_eligible_members';
    public const string ALREADY_EXCLUDED = 'data_request.refused.already_excluded';
    public const string NEW_VERSION_NEEDED = 'data_request.refused.new_version_needed';
    public const string COMMENT_INVALID = 'data_request.refused.comment_invalid';
    public const string QUERY_INVALID = 'data_request.refused.query_invalid';
    public const string QUERY_CHECK_FAILED = 'data_request.refused.query_check_failed';
    public const string RELEASE_INVALID = 'data_request.refused.release_invalid';
    public const string CONDITIONS_NOT_ACCEPTED = 'data_request.refused.conditions_not_accepted';

    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
