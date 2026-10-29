<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Demo;

/**
 * The wizard cannot generate the dataset; everything it had written was rolled back.
 */
final class DemoWizardRefused extends \DomainException
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('Nothing was written: '.implode(' ', $reasons));
    }
}
