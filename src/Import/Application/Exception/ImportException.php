<?php

namespace App\Import\Application\Exception;

class ImportException extends \RuntimeException
{
    public function __construct(
        string $message,
        protected ?string $field = null,
        protected ?string $value = null,
        protected ?string $codeStr = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function getCodeStr(): ?string
    {
        return $this->codeStr;
    }

    public function summarize(): string
    {
        $parts = [];

        if (null !== $this->codeStr && '' !== $this->codeStr) {
            $parts[] = $this->codeStr;
        }

        $parts[] = $this->getMessage();

        if (null !== $this->field && '' !== $this->field) {
            $parts[] = "field={$this->field}";
        }

        if (null !== $this->value) {
            $parts[] = 'value="'.$this->value.'"';
        }

        return implode(' | ', $parts);
    }

    /**
     * @return array<string,mixed>
     **/
    public function context(): array
    {
        return array_filter([
            'error_code' => $this->codeStr,
            'field' => $this->field,
            'value' => $this->value,
            'exception' => static::class,
            'message' => $this->getMessage(),
        ], static fn ($v) => null !== $v && '' !== $v);
    }
}
