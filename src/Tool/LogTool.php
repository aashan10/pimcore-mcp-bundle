<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Log\LogReader;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP tools for reading the application's Monolog log files.
 */
final class LogTool
{
    private const MAX_LINES = 1000;

    public function __construct(
        private readonly LogReader $reader,
    ) {}

    /**
     * List the available log files in the configured log directory.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_log_files', description: 'List the Monolog log files in the configured log directory (name, size, last modified).')]
    public function listLogFiles(): array
    {
        $files = $this->reader->files();

        return [
            'count' => \count($files),
            'files' => $files,
            '_next' => 'Use "list_log_channels" to see channels, or "get_log_entries" to read entries.',
        ];
    }

    /**
     * List the Monolog channels present in the log files (by scanning the tail).
     *
     * @param string|null $file     Restrict to a single log file (by name). Omit to scan all.
     * @param int         $scanKb   How much of each file's tail to scan, in KB (default 2048).
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_log_channels', description: 'List the Monolog channels found in the log files (scanning the tail), with occurrence counts. Use these channel names with get_log_entries.')]
    public function listLogChannels(?string $file = null, int $scanKb = 2048): array
    {
        if ($file !== null && $file !== '' && !$this->reader->fileExists($file)) {
            return $this->unknownFile($file);
        }

        $channels = $this->reader->channels($file, $scanKb * 1024);

        return [
            'channels' => array_map(
                static fn (string $name, int $count): array => ['channel' => $name, 'count' => $count],
                array_keys($channels),
                array_values($channels),
            ),
            'file' => $file,
        ];
    }

    /**
     * Get the last N log entries, optionally filtered by channel and minimum level.
     *
     * @param string|null $channel  Monolog channel to filter by (e.g. "php", "console", "admin_statistics"). Omit for all.
     * @param int         $lines    Number of most-recent entries to return (default 100, max 1000).
     * @param string|null $level    Minimum severity: DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY.
     * @param string|null $file     Restrict to a single log file (by name). Omit to search all files and merge by time.
     * @param int         $scanKb   How much of each file's tail to scan, in KB (default 2048, max 20480).
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_log_entries',
        description: 'Return the last N Monolog log entries, optionally filtered by channel and minimum level. Reads only the tail of each file. Entries have time, channel, level, message and source file.',
    )]
    public function getLogEntries(
        ?string $channel = null,
        int $lines = 100,
        ?string $level = null,
        ?string $file = null,
        int $scanKb = 2048,
    ): array {
        $lines = max(1, min($lines, self::MAX_LINES));

        if ($file !== null && $file !== '' && !$this->reader->fileExists($file)) {
            return $this->unknownFile($file);
        }
        if ($level !== null && $level !== '' && !\in_array(strtoupper($level), LogReader::levels(), true)) {
            return ['error' => \sprintf('Invalid level "%s". Allowed: %s.', $level, implode(', ', LogReader::levels()))];
        }

        $result = $this->reader->entries($file, $channel, $level, $lines, $scanKb * 1024);

        return [
            'count' => \count($result['entries']),
            'channel' => $channel,
            'minLevel' => $level !== null ? strtoupper($level) : null,
            'scannedFiles' => $result['scannedFiles'],
            'entries' => $result['entries'],
            '_note' => 'Only the tail of each file was scanned; increase "scanKb" if you expect older entries for a rare channel.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownFile(string $file): array
    {
        return [
            'error' => \sprintf('Log file "%s" was not found.', $file),
            '_hint' => 'Call "list_log_files" to see the available files.',
        ];
    }
}
