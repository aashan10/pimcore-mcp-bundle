<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Frontend;

/**
 * DB-free store for browser-reported errors/warnings.
 *
 * Records are appended as JSON Lines (one JSON object per line) to a single
 * file in the log directory, so the store is dependency-free and survives
 * across the long-lived MCP server process. Reads tail the file (bounded), and
 * the file is rotated once it grows past a cap so it can never grow unbounded.
 */
final class ClientErrorStore
{
    private const FILE = 'frontend-errors.jsonl';

    /** Accepted report kinds. Anything else is rejected on ingest. */
    public const TYPES = ['error', 'unhandledrejection', 'console.error', 'console.warn'];

    private const MAX_RECORDS_PER_REQUEST = 50;
    private const MAX_STRING = 4000;   // per free-text field (message/stack/url)
    private const MAX_FILE_BYTES = 5_242_880;  // 5 MB, then rotate
    private const READ_SCAN_BYTES = 4_194_304; // tail window for reads (4 MB)

    public function __construct(
        private readonly string $logDir,
    ) {}

    public function path(): string
    {
        return rtrim($this->logDir, '/') . '/' . self::FILE;
    }

    /**
     * Append a batch of client reports, enriched with server-side context.
     *
     * @param list<array<string, mixed>> $reports Raw client records.
     * @param array{ip?: ?string, ua?: ?string} $context Server-observed request context.
     *
     * @return int Number of records actually stored.
     */
    public function append(array $reports, array $context = []): int
    {
        if ($reports === []) {
            return 0;
        }
        if (!is_dir($this->logDir) && !@mkdir($this->logDir, 0775, true) && !is_dir($this->logDir)) {
            return 0;
        }

        $this->rotateIfNeeded();

        $lines = [];
        foreach (\array_slice($reports, 0, self::MAX_RECORDS_PER_REQUEST) as $report) {
            if (!\is_array($report)) {
                continue;
            }
            $record = $this->normalize($report, $context);
            if ($record === null) {
                continue;
            }
            $encoded = json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $lines[] = $encoded;
            }
        }
        if ($lines === []) {
            return 0;
        }

        $ok = @file_put_contents($this->path(), implode("\n", $lines) . "\n", \FILE_APPEND | \LOCK_EX);

        return $ok === false ? 0 : \count($lines);
    }

    /**
     * Most-recent stored reports (newest last), filtered.
     *
     * @param array{type?: ?string, surface?: ?string, since?: ?int} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit, array $filters = []): array
    {
        $type = self::nonEmpty($filters['type'] ?? null);
        $surface = self::nonEmpty($filters['surface'] ?? null);
        $since = $filters['since'] ?? null;

        $records = [];
        foreach ($this->tailLines() as $line) {
            $record = json_decode($line, true);
            if (!\is_array($record)) {
                continue;
            }
            if ($type !== null && ($record['type'] ?? null) !== $type) {
                continue;
            }
            if ($surface !== null && ($record['surface'] ?? null) !== $surface) {
                continue;
            }
            if ($since !== null && (int) ($record['ts'] ?? 0) < $since) {
                continue;
            }
            $records[] = $record;
        }

        return \array_slice($records, -max(1, $limit));
    }

    /**
     * Occurrence counts grouped by type across the tail window.
     *
     * @return array{total: int, byType: array<string, int>, bySurface: array<string, int>}
     */
    public function stats(): array
    {
        $byType = [];
        $bySurface = [];
        $total = 0;
        foreach ($this->tailLines() as $line) {
            $record = json_decode($line, true);
            if (!\is_array($record)) {
                continue;
            }
            $total++;
            $t = (string) ($record['type'] ?? 'unknown');
            $s = (string) ($record['surface'] ?? 'unknown');
            $byType[$t] = ($byType[$t] ?? 0) + 1;
            $bySurface[$s] = ($bySurface[$s] ?? 0) + 1;
        }

        return ['total' => $total, 'byType' => $byType, 'bySurface' => $bySurface];
    }

    /**
     * Delete all stored reports. Returns true if a file was removed.
     */
    public function clear(): bool
    {
        $path = $this->path();

        return is_file($path) ? @unlink($path) : false;
    }

    /**
     * @param array<string, mixed> $report
     * @param array{ip?: ?string, ua?: ?string} $context
     *
     * @return array<string, mixed>|null Null if the report is not a valid kind.
     */
    private function normalize(array $report, array $context): ?array
    {
        $type = (string) ($report['type'] ?? '');
        if (!\in_array($type, self::TYPES, true)) {
            return null;
        }

        $url = self::clip($report['url'] ?? null);

        return array_filter(
            [
                'ts' => $this->serverNowMs(),
                'clientTs' => isset($report['ts']) && is_numeric($report['ts']) ? (int) $report['ts'] : null,
                'type' => $type,
                'message' => self::clip($report['message'] ?? null),
                'stack' => self::clip($report['stack'] ?? null),
                'source' => self::clip($report['source'] ?? null, 1000),
                'line' => isset($report['line']) && is_numeric($report['line']) ? (int) $report['line'] : null,
                'col' => isset($report['col']) && is_numeric($report['col']) ? (int) $report['col'] : null,
                'url' => $url,
                'surface' => $this->surfaceFor($url),
                'ua' => self::clip($context['ua'] ?? null, 500),
                'ip' => self::nonEmpty($context['ip'] ?? null),
            ],
            static fn (mixed $v): bool => $v !== null && $v !== '',
        );
    }

    /**
     * Classify the reporting page as admin vs. website from its URL path.
     */
    private function surfaceFor(?string $url): string
    {
        if ($url === null) {
            return 'unknown';
        }
        $path = (string) (parse_url($url, \PHP_URL_PATH) ?? '');

        return str_starts_with($path, '/admin') ? 'admin' : 'website';
    }

    /**
     * @return list<string>
     */
    private function tailLines(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }
        $size = (int) filesize($path);
        $read = min($size, self::READ_SCAN_BYTES);

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        if ($size > $read) {
            fseek($handle, $size - $read);
        }
        $data = (string) fread($handle, $read);
        fclose($handle);

        $lines = explode("\n", $data);
        if ($size > $read) {
            array_shift($lines); // drop the partial first line
        }

        return array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
    }

    private function rotateIfNeeded(): void
    {
        $path = $this->path();
        if (is_file($path) && (int) filesize($path) > self::MAX_FILE_BYTES) {
            @rename($path, $path . '.1'); // keep one generation, start fresh
        }
    }

    private function serverNowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private static function clip(mixed $value, int $max = self::MAX_STRING): ?string
    {
        if (!\is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return \strlen($value) > $max ? substr($value, 0, $max) . '… [truncated]' : $value;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
