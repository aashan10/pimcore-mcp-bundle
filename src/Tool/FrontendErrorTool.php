<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Frontend\ClientErrorStore;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP tools for reading browser-side errors/warnings captured from the running
 * site. The collector script (auto-injected in debug mode) reports uncaught JS
 * errors, unhandled promise rejections and console.error / console.warn to an
 * ingest endpoint; these tools read what it stored.
 */
final class FrontendErrorTool
{
    private const MAX_LIMIT = 500;

    public function __construct(
        private readonly ClientErrorStore $store,
    ) {}

    /**
     * Get recent browser-captured errors/warnings (newest last).
     *
     * @param int         $limit   Max entries to return (default 100, max 500).
     * @param string|null $type    Filter by kind: "error", "unhandledrejection", "console.error", "console.warn".
     * @param string|null $surface Filter by page surface: "website" or "admin".
     * @param int|null    $sinceMs Only entries recorded at/after this server epoch-millisecond timestamp.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_frontend_errors',
        description: 'Return recent browser-side errors/warnings captured from the running site (uncaught JS errors, unhandled promise rejections, console.error/warn), with message, stack, source, url and surface. Newest last.',
    )]
    public function getFrontendErrors(
        int $limit = 100,
        ?string $type = null,
        ?string $surface = null,
        ?int $sinceMs = null,
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        if ($type !== null && $type !== '' && !\in_array($type, ClientErrorStore::TYPES, true)) {
            return ['error' => \sprintf('Invalid type "%s". Allowed: %s.', $type, implode(', ', ClientErrorStore::TYPES))];
        }
        if ($surface !== null && $surface !== '' && !\in_array($surface, ['website', 'admin'], true)) {
            return ['error' => 'Invalid surface. Allowed: website, admin.'];
        }

        $entries = $this->store->recent($limit, [
            'type' => $type,
            'surface' => $surface,
            'since' => $sinceMs,
        ]);

        return [
            'count' => \count($entries),
            'filters' => array_filter(
                ['type' => $type, 'surface' => $surface, 'sinceMs' => $sinceMs],
                static fn (mixed $v): bool => $v !== null && $v !== '',
            ),
            'entries' => $entries,
            '_note' => 'Capture requires the app in debug mode; the collector is auto-injected into HTML pages. Use "clear_frontend_errors" to reset before reproducing an issue.',
        ];
    }

    /**
     * Summary counts of stored browser errors/warnings by type and surface.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'frontend_error_stats',
        description: 'Summarise stored browser-side errors/warnings: total plus counts grouped by type and by surface (website/admin).',
    )]
    public function frontendErrorStats(): array
    {
        return $this->store->stats();
    }

    /**
     * Delete all stored browser errors/warnings.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'clear_frontend_errors',
        description: 'Delete all stored browser-side errors/warnings. Call this before reproducing an issue so get_frontend_errors returns only the new reports.',
    )]
    public function clearFrontendErrors(): array
    {
        $cleared = $this->store->clear();

        return [
            'cleared' => $cleared,
            '_note' => $cleared ? 'Store reset.' : 'Nothing to clear (no reports stored yet).',
        ];
    }
}
