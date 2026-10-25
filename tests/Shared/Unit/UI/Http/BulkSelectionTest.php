<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\UI\Http;

use App\Shared\UI\Http\BulkSelection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BulkSelectionTest extends TestCase
{
    public function testKeepsValidIdsOnceInPostedOrder(): void
    {
        $request = new Request(request: ['ids' => ['7', '3', '7', '0', '-2', 'abc', '12345678901', '42']]);

        self::assertSame([7, 3, 42], BulkSelection::ids($request));
    }

    public function testMissingSelectionIsEmpty(): void
    {
        self::assertSame([], BulkSelection::ids(new Request()));
    }

    public function testRejectsMoreIdsThanTheMaximum(): void
    {
        $request = new Request(request: ['ids' => ['1', '2', '3']]);

        $this->expectException(BadRequestHttpException::class);

        BulkSelection::ids($request, 2);
    }
}
