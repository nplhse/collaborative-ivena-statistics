<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

/**
 * Parses and formats the PZC codes of a problem area as typed on the review page: codes and ranges separated by
 * commas, semicolons or white space, for example "711-718, 263". Codes have three digits (leading zeros optional).
 */
final class PzcCodeList
{
    /**
     * @return list<int> unique, ascending
     *
     * @throws PlausibilityException with reason PZC_INVALID for the first token that is not a code or range
     */
    public static function parse(string $input): array
    {
        $codes = [];
        foreach (preg_split('/[\s,;]+/u', trim($input), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (1 === preg_match('/^(\d{1,3})$/', $token, $match)) {
                $codes[] = (int) $match[1];

                continue;
            }

            if (1 === preg_match('/^(\d{1,3})[-–](\d{1,3})$/u', $token, $match) && (int) $match[1] <= (int) $match[2]) {
                array_push($codes, ...range((int) $match[1], (int) $match[2]));

                continue;
            }

            throw new PlausibilityException(PlausibilityException::PZC_INVALID, ['pzc' => $token]);
        }

        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * @param list<int> $codes
     */
    public static function format(array $codes): string
    {
        sort($codes);

        return implode(', ', array_map(static fn (int $code): string => \sprintf('%03d', $code), $codes));
    }
}
