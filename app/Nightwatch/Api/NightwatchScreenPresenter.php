<?php

namespace App\Nightwatch\Api;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

use function array_filter;
use function array_map;
use function ceil;
use function count;
use function floor;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function parse_url;
use function round;
use function sprintf;
use function strlen;
use function strrpos;
use function substr;

final class NightwatchScreenPresenter
{
    public function cell(string $text, array $extras = []): array
    {
        return ['text' => $text, ...$extras];
    }

    public function meta(string $label, string $tone): array
    {
        return [
            'label' => $label,
            'tone' => $tone,
        ];
    }

    public function info(string $label, string $value): array
    {
        return [
            'label' => $label,
            'value' => $value,
        ];
    }

    public function timelineRow(string $label, string $value, float $width, string $tone): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'width' => (int) round($width),
            'tone' => $tone,
        ];
    }

    public function backTo(string $screenKey): array
    {
        return [
            'name' => 'screen',
            'params' => ['screenKey' => $screenKey],
        ];
    }

    public function routeLabel(?string $method, ?string $routePath, ?string $url): string
    {
        $path = $routePath ?: (string) parse_url((string) $url, PHP_URL_PATH) ?: '/';

        return trim(sprintf('%s %s', $method ?: 'HTTP', $path));
    }

    public function duration(int|float|null $microseconds): string
    {
        $value = (float) ($microseconds ?? 0);

        if ($value >= 1_000_000) {
            return sprintf('%.2fs', $value / 1_000_000);
        }

        if ($value >= 1_000) {
            return sprintf('%.2fms', $value / 1_000);
        }

        return sprintf('%dμs', (int) round($value));
    }

    public function bytes(int|float|null $bytes): string
    {
        $value = (float) ($bytes ?? 0);

        if ($value >= 1_048_576) {
            return sprintf('%.2fMB', $value / 1_048_576);
        }

        if ($value >= 1024) {
            return sprintf('%.2fkB', $value / 1024);
        }

        return sprintf('%dB', (int) round($value));
    }

    public function longTimestamp(CarbonInterface|string|null $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return CarbonImmutable::parse($value)->timezone(config('app.timezone'))->format('M j, Y, H:i:s P');
    }

    public function shortTimestamp(CarbonInterface|string|null $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        $date = CarbonImmutable::parse($value)->timezone(config('app.timezone'));
        $offset = $date->format('P');

        if (substr($offset, -3) === ':00') {
            $offset = substr($offset, 0, -3);
        }

        return $date->format('H:i:s').' '.$offset;
    }

    public function statusCodeTone(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 500 => 'red',
            $statusCode >= 400 => 'yellow',
            $statusCode >= 300 => 'sky',
            default => 'green',
        };
    }

    public function executionTone(?string $status): string
    {
        return match ($status) {
            'error', 'failed', 'exception' => 'red',
            'failure', 'released', 'skipped' => 'yellow',
            default => 'green',
        };
    }

    public function handledTone(bool $handled): string
    {
        return $handled ? 'green' : 'red';
    }

    public function normalizeSeries(array $values): array
    {
        $max = max($values ?: [0]);

        if ($max <= 0) {
            return array_map(static fn (): float => 0.0, $values);
        }

        return array_map(static fn ($value): float => round(((float) $value) / $max, 4), $values);
    }

    public function sparkBars(array $values, string $tone = 'blue'): array
    {
        return array_map(
            fn (float $value): array => ['value' => (int) round($value * 100), 'tone' => $tone],
            $this->normalizeSeries($values),
        );
    }

    public function multiToneSparkBars(array $entries): array
    {
        $values = array_map(static fn (array $entry): float => (float) ($entry['value'] ?? 0), $entries);
        $normalized = $this->normalizeSeries($values);

        return array_map(
            static fn (array $entry, float $value): array => [
                'value' => (int) round($value * 100),
                'tone' => $entry['tone'] ?? 'blue',
            ],
            $entries,
            $normalized,
        );
    }

    public function percentile(array $values, float $percentile): int
    {
        $values = array_values(array_filter($values, static fn ($value): bool => $value !== null));

        if ($values === []) {
            return 0;
        }

        sort($values);

        $index = (int) ceil($percentile * count($values)) - 1;
        $index = max(0, min($index, count($values) - 1));

        return (int) $values[$index];
    }

    public function paginate(Collection $items, int $page, int $perPage): array
    {
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $currentPage = max(1, min($page, $lastPage));

        return [
            'items' => $items->forPage($currentPage, $perPage)->values(),
            'meta' => [
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? null : (($currentPage - 1) * $perPage) + 1,
                'to' => $total === 0 ? null : min($currentPage * $perPage, $total),
            ],
        ];
    }

    public function decodeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function prettyJson(mixed $value): string
    {
        $decoded = $this->decodeJson($value);

        if ($decoded === null) {
            return 'n/a';
        }

        if (is_string($decoded)) {
            return $decoded;
        }

        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function headersToText(mixed $value): string
    {
        $headers = $this->decodeJson($value);

        if (! is_array($headers) || $headers === []) {
            return 'n/a';
        }

        $lines = [];

        foreach ($headers as $key => $headerValue) {
            if (is_array($headerValue)) {
                $headerValue = implode(', ', array_map('strval', $headerValue));
            }

            $lines[] = sprintf('%s: %s', $key, $headerValue);
        }

        return implode("\n", $lines);
    }

    public function traceFramesToText(mixed $value): string
    {
        $frames = $this->decodeJson($value);

        if (! is_array($frames) || $frames === []) {
            return 'n/a';
        }

        $lines = [];

        foreach ($frames as $index => $frame) {
            if (! is_array($frame)) {
                continue;
            }

            $location = $frame['file'] ?? 'unknown';
            $source = $frame['source'] ?? 'unknown';
            $lines[] = sprintf('#%d %s', $index, $location);
            $lines[] = sprintf('   %s', $source);

            $code = $frame['code'] ?? null;

            if (is_array($code) && $code !== []) {
                foreach ($code as $line => $snippet) {
                    $lines[] = sprintf('   %s | %s', $line, $snippet);
                }
            }
        }

        return implode("\n", $lines);
    }

    public function exceptionPreview(
        string $class,
        string $message,
        ?string $file,
        ?int $line,
        mixed $traceFrames,
    ): string {
        $frames = $this->decodeJson($traceFrames);
        $lines = [sprintf('%s: %s', $this->basename($class), $message)];

        if ($file !== null && $line !== null) {
            $lines[] = '';
            $lines[] = sprintf('at %s:%d', $file, $line);
        }

        if (is_array($frames) && isset($frames[0]) && is_array($frames[0])) {
            $code = $frames[0]['code'] ?? null;

            if (is_array($code) && $code !== []) {
                $lines[] = '';

                foreach ($code as $frameLine => $snippet) {
                    $lines[] = sprintf('%s | %s', $frameLine, $snippet);
                }
            }
        }

        return implode("\n", $lines);
    }

    public function basename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
