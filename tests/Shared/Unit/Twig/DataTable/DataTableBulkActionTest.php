<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\DataTable;

use App\Shared\UI\Twig\DataTable\DataTableBulkAction;
use PHPUnit\Framework\TestCase;

final class DataTableBulkActionTest extends TestCase
{
    public function testActionWithoutTitlesSubmitsDirectly(): void
    {
        self::assertFalse(new DataTableBulkAction('reprocess', 'Reprocess')->requiresConfirmation());
    }

    public function testActionWithTitlesNeedsConfirmation(): void
    {
        $action = new DataTableBulkAction('delete', 'Delete', confirmTitleOne: 'Delete this one?', confirmTitleOther: 'Delete these %count%?');

        self::assertTrue($action->requiresConfirmation());
    }

    public function testRejectsNamesThatAreNoSafeIdentifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DataTableBulkAction('delete all', 'Delete');
    }

    public function testRejectsOnlyOneConfirmationTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DataTableBulkAction('delete', 'Delete', confirmTitleOther: 'Delete these %count%?');
    }
}
