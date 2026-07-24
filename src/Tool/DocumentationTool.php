<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Documentation\DocumentationException;
use Aashan\PimcoreMcpBundle\Documentation\DocumentationFetcher;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP tools that serve the official Pimcore documentation (docs.pimcore.com)
 * matched to the running product version.
 *
 * `list_documentation_topics` browses the sidebar navigation (the page's
 * `<aside>`), and `get_documentation_page` returns a single page's content as
 * markdown. The docs version is resolved from the Pimcore version automatically
 * but can be overridden with `docsVersion`.
 */
final class DocumentationTool
{
    public function __construct(
        private readonly DocumentationFetcher $fetcher,
    ) {}

    /**
     * Browse the documentation navigation for the current Pimcore version.
     *
     * Returns the sidebar tree (title + relative path per node). The docs site
     * only expands the subtree along the requested path, so pass `section` to
     * drill into a top-level topic (e.g. "Pimcore" or "Pimcore/Objects") and
     * reveal its child pages. Feed a node's `path` to `get_documentation_page`.
     *
     * @param string|null $section     Relative section path to expand (e.g. "Pimcore/Objects"). Omit for the top level.
     * @param string|null $docsVersion Force a docs version ("YYYY.N", e.g. "2024.4"). Omit to auto-resolve from the Pimcore version.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_documentation_topics',
        description: 'Browse the official Pimcore documentation navigation (docs.pimcore.com) for the running product version. Returns the sidebar topic tree (title + path). Pass "section" to expand a topic and reveal its pages, then read one with get_documentation_page. Optionally pin a docs version with "docsVersion".',
    )]
    public function listDocumentationTopics(?string $section = null, ?string $docsVersion = null): array
    {
        try {
            $resolved = $this->fetcher->resolveVersion($docsVersion);
            $nav = $this->fetcher->fetchNavigation($resolved['version'], $section);
        } catch (DocumentationException $e) {
            return $this->error($e);
        }

        return [
            'docsVersion' => $resolved['version'],
            'versionSource' => $resolved['source'],
            'availableVersions' => $resolved['available'],
            'section' => $nav['path'],
            'sourceUrl' => $nav['url'],
            'topics' => $nav['topics'],
            '_note' => $resolved['note'] ?? 'Pass a node "path" to get_documentation_page to read it, or to this tool as "section" to expand its children.',
        ];
    }

    /**
     * Fetch one documentation page and return its content as markdown.
     *
     * @param string      $path        Relative page path from the navigation (e.g. "Pimcore/Objects/Object_Classes"). A full docs URL is also accepted.
     * @param string|null $docsVersion Force a docs version ("YYYY.N", e.g. "2024.4"). Omit to auto-resolve from the Pimcore version.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_documentation_page',
        description: 'Fetch one Pimcore documentation page (docs.pimcore.com) for the running product version and return its content as markdown. Use the "path" of a node from list_documentation_topics (e.g. "Pimcore/Objects/Object_Classes").',
    )]
    public function getDocumentationPage(string $path, ?string $docsVersion = null): array
    {
        if (trim($path) === '') {
            return ['error' => 'A "path" is required.', '_hint' => 'Call list_documentation_topics first and use a node\'s "path".'];
        }

        try {
            $resolved = $this->fetcher->resolveVersion($docsVersion);
            $page = $this->fetcher->fetchPage($resolved['version'], $path);
        } catch (DocumentationException $e) {
            return $this->error($e);
        }

        return [
            'docsVersion' => $resolved['version'],
            'title' => $page['title'],
            'url' => $page['url'],
            'truncated' => $page['truncated'],
            'markdown' => $page['markdown'],
            '_note' => $page['truncated']
                ? 'Content was truncated; open the "url" for the full page.'
                : 'Use list_documentation_topics to find related pages.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(DocumentationException $e): array
    {
        return [
            'error' => $e->getMessage(),
            '_hint' => 'Call list_documentation_topics to see available versions and topics.',
        ];
    }
}
