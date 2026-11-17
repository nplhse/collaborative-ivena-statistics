<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Curation\Import;

use Cois\Plugin\EdVisit\Application\Curation\Package\AreaRuleData;

/**
 * PZC codes and rules the import adds to an existing problem area; what the area already has stays as it is.
 */
final readonly class AreaExtension
{
    /**
     * @param list<int>          $pzc
     * @param list<AreaRuleData> $rules
     */
    public function __construct(
        public string $code,
        public array $pzc,
        public array $rules,
    ) {
    }
}
