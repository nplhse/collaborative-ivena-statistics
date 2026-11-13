<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * How the ED visit ended, `disposition` of the `ed_visit` exchange format (#1047).
 *
 * Admission within the own hospital and external transfer are separate dispositions (ADR 026); only
 * these two carry a care level.
 */
enum Disposition: string
{
    case Home = 'home';
    case ToPhysician = 'to_physician';
    case KvPractice = 'kv_practice';
    case AgainstAdvice = 'against_advice';
    case LeftBeforeCompletion = 'left_before_completion';
    case Admitted = 'admitted';
    case TransferredExternal = 'transferred_external';
    case Died = 'died';
    case Other = 'other';

    public function carriesCareLevel(): bool
    {
        return self::Admitted === $this || self::TransferredExternal === $this;
    }
}
