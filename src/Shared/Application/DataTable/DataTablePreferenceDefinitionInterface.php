<?php

declare(strict_types=1);

namespace App\Shared\Application\DataTable;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.data_table_preference_definition')]
interface DataTablePreferenceDefinitionInterface
{
    public function preferenceSchema(): DataTablePreferenceSchema;
}
