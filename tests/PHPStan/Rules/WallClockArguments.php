<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;

/**
 * Finds call arguments by position or name and tells whether a date argument reads the wall clock.
 */
final class WallClockArguments
{
    /**
     * Returns the argument at the given position or with the given name, or null when it is missing.
     * Calls with argument unpacking are treated as having every argument.
     */
    public static function find(CallLike $call, int $position, string $name): ?Arg
    {
        $index = 0;
        foreach ($call->getArgs() as $arg) {
            if (null !== $arg->name) {
                if ($arg->name->toString() === $name) {
                    return $arg;
                }

                continue;
            }

            if ($arg->unpack || $index === $position) {
                return $arg;
            }

            ++$index;
        }

        return null;
    }

    public static function has(CallLike $call, int $position, string $name): bool
    {
        return self::find($call, $position, $name) instanceof Arg;
    }

    /**
     * Returns a short description of the date argument when it reads the wall clock, null otherwise.
     * A missing argument and a constant string that is relative in at least one branch read the wall clock; other expressions are
     * not reported, because they almost always carry an absolute value from the database or request.
     */
    public static function describeFirstDateArgument(CallLike $call, Scope $scope, string $name): ?string
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $arg = self::find($call, 0, $name);
        if (!$arg instanceof Arg) {
            return '';
        }

        if ($arg->unpack) {
            return null;
        }

        $strings = $scope->getType($arg->value)->getConstantStrings();
        if ([] === $strings) {
            return null;
        }

        foreach ($strings as $string) {
            if (RelativeDateString::isRelative($string->getValue())) {
                return var_export($string->getValue(), true);
            }
        }

        return null;
    }
}
