<?php

declare(strict_types=1);

namespace App\Admin\Application\Service\JobRun;

use App\Admin\Infrastructure\Repository\HospitalNameRepository;
use App\Shared\Infrastructure\Monitoring\JobRun\Entity\JobRun;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Turns job_run values into readable text for the admin list: durations, memory, hospitals and the metrics table.
 */
final readonly class JobRunFormatter
{
    public function __construct(
        private HospitalNameRepository $hospitalNames,
    ) {
    }

    public static function duration(?int $ms): string
    {
        if (null === $ms) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms.' ms';
        }
        if ($ms < 60_000) {
            return number_format($ms / 1000, 1, '.', '').' s';
        }
        $seconds = intdiv($ms, 1000);
        if ($seconds < 3600) {
            return sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    public static function memory(?int $bytes): string
    {
        if (null === $bytes) {
            return '—';
        }

        return number_format($bytes / 1_048_576, 1, '.', '').' MB';
    }

    public function hospital(?int $id): string
    {
        if (null === $id) {
            return '—';
        }
        $name = $this->hospitalNames->nameOf($id);

        return $name ?? '#'.$id;
    }

    /**
     * Hospital filter choices: name => id.
     *
     * @return array<string, int>
     */
    public function hospitalChoices(): array
    {
        $choices = [];
        foreach ($this->hospitalNames->all() as $id => $name) {
            $label = '' === $name ? '#'.$id : $name;
            $choices[isset($choices[$label]) ? $label.' (#'.$id.')' : $label] = $id;
        }

        return $choices;
    }

    /**
     * The metrics of a run as rows: known keys of the job in their order, then every other key.
     *
     * @return list<array{key: string, label: TranslatableInterface|string, value: string}>
     */
    public function metricsRows(JobRun $run): array
    {
        $metrics = $run->getMetrics();
        $formats = JobRunCatalog::metricFormats($run->getJob());

        $rows = [];
        foreach ($formats as $key => $format) {
            if (\array_key_exists($key, $metrics)) {
                $rows[] = $this->row($key, $format, $metrics[$key]);
                unset($metrics[$key]);
            }
        }
        ksort($metrics);
        foreach ($metrics as $key => $value) {
            $rows[] = $this->row($key, str_ends_with($key, '_ms') ? JobRunCatalog::FORMAT_MS : JobRunCatalog::FORMAT_TEXT, $value);
        }

        return $rows;
    }

    /**
     * @return array{key: string, label: TranslatableInterface|string, value: string}
     */
    private function row(string $key, string $format, mixed $value): array
    {
        return ['key' => $key, 'label' => JobRunCatalog::metricLabel($key), 'value' => $this->value($format, $value)];
    }

    private function value(string $format, mixed $value): string
    {
        if (null === $value) {
            return '—';
        }

        return match ($format) {
            JobRunCatalog::FORMAT_MS => \is_int($value) || is_numeric($value) ? self::duration((int) $value) : $this->scalar($value),
            JobRunCatalog::FORMAT_COUNT => \is_int($value) ? number_format($value, 0, '', ' ') : $this->scalar($value),
            JobRunCatalog::FORMAT_BOOL => \is_bool($value) ? ($value ? '✓' : '✗') : $this->scalar($value),
            JobRunCatalog::FORMAT_HOSPITALS => \is_array($value) ? $this->hospitals($value) : $this->scalar($value),
            default => $this->scalar($value),
        };
    }

    /**
     * @param array<mixed> $ids
     */
    private function hospitals(array $ids): string
    {
        if ([] === $ids) {
            return '—';
        }
        $names = [];
        foreach ($ids as $id) {
            $names[] = \is_int($id) || (\is_string($id) && ctype_digit($id)) ? $this->hospital((int) $id) : $this->scalar($id);
        }

        return implode(', ', $names);
    }

    private function scalar(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return false === $json ? '' : $json;
    }
}
