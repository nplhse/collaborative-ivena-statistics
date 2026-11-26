<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

/**
 * Why an export query cannot be released. The code is a translation key suffix, the parameters fill it.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the template and stored with the query version.
 */
final readonly class QueryViolation
{
    public const string NOT_SELECT = 'not_select';
    public const string SEVERAL_STATEMENTS = 'several_statements';
    public const string DENIED_FUNCTION = 'denied_function';
    public const string TABLE = 'table';
    public const string COLUMN = 'column';
    public const string INTERNAL_OUTPUT = 'internal_output';
    public const string RESERVED_MISSING = 'reserved_missing';
    public const string SQL_ERROR = 'sql_error';
    public const string CONNECTION_MISSING = 'connection_missing';
    public const string CONNECTION_WRITABLE = 'connection_writable';
    public const string UNICODE_ESCAPE = 'unicode_escape';

    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public string $code,
        public array $parameters = [],
    ) {
    }

    /** @return array{code: string, parameters: array<string, string>} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'parameters' => $this->parameters];
    }
}
