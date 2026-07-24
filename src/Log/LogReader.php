<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Log;

/**
 * Reads the Monolog log files in the configured log directory
 * (`%kernel.logs_dir%`), extracts channels and tails entries.
 *
 * Every record is written by Monolog's LineFormatter as a single line:
 *   [datetime] channel.LEVEL: message context extra
 * so one physical line maps to one entry. Reads are bounded: only the tail of
 * each (potentially huge) file is scanned.
 */
final class LogReader
{
    /** Monolog severity order, used for "minimum level" filtering. */
    private const LEVELS = [
        'DEBUG' => 100,
        'INFO' => 200,
        'NOTICE' => 250,
        'WARNING' => 300,
        'ERROR' => 400,
        'CRITICAL' => 500,
        'ALERT' => 550,
        'EMERGENCY' => 600,
    ];

    private const LINE_PATTERN = '/^\[(?<time>[^\]]+)\]\s+(?<channel>\S+?)\.(?<level>DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):\s+(?<message>.*)$/';

    private const DEFAULT_SCAN_BYTES = 2_097_152; // 2 MB
    private const MAX_SCAN_BYTES = 20_971_520;     // 20 MB
    private const MAX_MESSAGE = 2000;

    public function __construct(
        private readonly string $logDir,
    ) {}

    public static function levels(): array
    {
        return array_keys(self::LEVELS);
    }

    /**
     * @return list<array{name: string, bytes: int, size: string, modified: int}>
     */
    public function files(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }

        $files = [];
        foreach (glob(rtrim($this->logDir, '/') . '/*.log') ?: [] as $path) {
            $files[] = [
                'name' => basename($path),
                'bytes' => (int) filesize($path),
                'size' => $this->humanSize((int) filesize($path)),
                'modified' => (int) filemtime($path),
            ];
        }

        usort($files, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * Distinct channels found while scanning the tail of the given file (or all files).
     *
     * @return array<string, int> channel => occurrence count within the scan window
     */
    public function channels(?string $file, int $scanBytes): array
    {
        $channels = [];
        foreach ($this->resolveFiles($file) as $path) {
            foreach ($this->parse($this->tail($path, $scanBytes)) as $entry) {
                $channels[$entry['channel']] = ($channels[$entry['channel']] ?? 0) + 1;
            }
        }
        arsort($channels);

        return $channels;
    }

    /**
     * Last $limit entries, optionally filtered by channel and minimum level.
     *
     * @return array{entries: list<array<string, mixed>>, scannedFiles: list<string>, scannedBytes: int}
     */
    public function entries(?string $file, ?string $channel, ?string $minLevel, int $limit, int $scanBytes): array
    {
        $minSeverity = $minLevel !== null ? (self::LEVELS[strtoupper($minLevel)] ?? null) : null;
        $channel = $channel !== null && $channel !== '' ? $channel : null;

        $collected = [];
        $scannedFiles = [];
        foreach ($this->resolveFiles($file) as $path) {
            $scannedFiles[] = basename($path);
            foreach ($this->parse($this->tail($path, $scanBytes)) as $entry) {
                if ($channel !== null && strcasecmp($entry['channel'], $channel) !== 0) {
                    continue;
                }
                if ($minSeverity !== null && (self::LEVELS[$entry['level']] ?? 0) < $minSeverity) {
                    continue;
                }
                $entry['file'] = basename($path);
                $collected[] = $entry;
            }
        }

        // Merge chronologically across files, then keep the most recent $limit.
        usort($collected, static fn (array $a, array $b): int => $a['_sort'] <=> $b['_sort']);
        $collected = \array_slice($collected, -$limit);

        $entries = array_map(static function (array $e): array {
            unset($e['_sort']);

            return $e;
        }, $collected);

        return [
            'entries' => $entries,
            'scannedFiles' => $scannedFiles,
            'scannedBytes' => $this->clampScanBytes($scanBytes),
        ];
    }

    public function fileExists(string $file): bool
    {
        return \in_array($file, array_map(static fn (array $f): string => $f['name'], $this->files()), true);
    }

    /**
     * @return list<string> absolute paths
     */
    private function resolveFiles(?string $file): array
    {
        if ($file !== null && $file !== '') {
            $name = basename($file); // prevent path traversal
            $path = rtrim($this->logDir, '/') . '/' . $name;

            return is_file($path) ? [$path] : [];
        }

        return array_map(fn (array $f): string => rtrim($this->logDir, '/') . '/' . $f['name'], $this->files());
    }

    /**
     * Read the last $scanBytes of a file and return its complete lines.
     *
     * @return list<string>
     */
    private function tail(string $path, int $scanBytes): array
    {
        $scanBytes = $this->clampScanBytes($scanBytes);
        $size = (int) filesize($path);
        $readBytes = min($size, $scanBytes);

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        if ($size > $readBytes) {
            fseek($handle, $size - $readBytes);
        }
        $data = (string) fread($handle, $readBytes);
        fclose($handle);

        $lines = explode("\n", $data);
        // The first line is probably truncated when we started mid-file.
        if ($size > $readBytes) {
            array_shift($lines);
        }

        return array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<array{time: string, channel: string, level: string, message: string, _sort: string}>
     */
    private function parse(array $lines): array
    {
        $entries = [];
        foreach ($lines as $line) {
            if (preg_match(self::LINE_PATTERN, $line, $m) !== 1) {
                // Continuation of the previous entry (rare multi-line records).
                if ($entries !== []) {
                    $last = \count($entries) - 1;
                    $entries[$last]['message'] = substr($entries[$last]['message'] . "\n" . $line, 0, self::MAX_MESSAGE);
                }
                continue;
            }

            $message = $m['message'];
            if (\strlen($message) > self::MAX_MESSAGE) {
                $message = substr($message, 0, self::MAX_MESSAGE) . '… [truncated]';
            }

            $entries[] = [
                'time' => $m['time'],
                'channel' => $m['channel'],
                'level' => $m['level'],
                'message' => $message,
                '_sort' => $m['time'],
            ];
        }

        return $entries;
    }

    private function clampScanBytes(int $scanBytes): int
    {
        if ($scanBytes <= 0) {
            return self::DEFAULT_SCAN_BYTES;
        }

        return min($scanBytes, self::MAX_SCAN_BYTES);
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return \sprintf('%.1f %s', $value, $units[$i]);
    }
}
