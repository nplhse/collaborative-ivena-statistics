<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reads the selected row ids (`ids[]`) of a bulk action request.
 *
 * Invalid values are dropped and duplicates removed, in the posted order. More ids than the maximum
 * are rejected: a page shows at most 100 rows, so a larger request was not built by the UI.
 */
final class BulkSelection
{
    public const int MAX_IDS = 100;

    /**
     * @return list<int>
     *
     * @throws BadRequestHttpException when more than $max ids are posted
     */
    public static function ids(Request $request, int $max = self::MAX_IDS): array
    {
        $raw = $request->request->all('ids');
        if (\count($raw) > $max) {
            throw new BadRequestHttpException(\sprintf('At most %d entries can be changed at once.', $max));
        }

        $ids = [];
        foreach ($raw as $value) {
            if (\is_string($value) && 1 === preg_match('/^[1-9]\d{0,9}$/', $value)) {
                $ids[(int) $value] = (int) $value;
            }
        }

        return array_values($ids);
    }
}
