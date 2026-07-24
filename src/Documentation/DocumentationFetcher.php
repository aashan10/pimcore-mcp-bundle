<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Documentation;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use League\HTMLToMarkdown\HtmlConverter;
use Pimcore\Version;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Fetches Pimcore documentation from docs.pimcore.com for the running product
 * version.
 *
 * Pimcore's online documentation is a Docusaurus site served per platform
 * version under `/platform/<version>` (e.g. `/platform/2023.3`). The version is
 * derived from the running Pimcore instance:
 *
 * - `Version::getPlatformVersion()` (the `pimcore/platform-version` package)
 *   maps directly onto the docs `<version>` path segment when available;
 * - otherwise, for Pimcore >= 11 we fall back to the newest published version
 *   that is >= the {@see self::FLOOR_VERSION} floor (`2023.3`, the first
 *   platform-versioned docs set for Pimcore 11).
 *
 * The sidebar navigation lives in the page's `<aside>` element as a nested
 * `ul.menu__list` of `a.menu__link` anchors; Docusaurus only expands the
 * subtree along the current path, so drilling into a section reveals its
 * children. Page bodies live in `article .theme-doc-markdown`.
 *
 * This service is intentionally DB-free: it only performs outbound HTTP.
 */
final class DocumentationFetcher
{
    public const BASE_URL = 'https://docs.pimcore.com';

    /** First platform-versioned docs set; the floor for Pimcore 11. */
    public const FLOOR_VERSION = '2023.3';

    /** Minimum Pimcore major version supported by the platform docs. */
    private const MIN_MAJOR = 11;

    /** Fallback when the available-versions list cannot be discovered. */
    private const DEFAULT_VERSION = self::FLOOR_VERSION;

    private const HTTP_TIMEOUT = 15.0;

    /** Hard cap on returned page markdown to keep responses bounded. */
    private const MAX_PAGE_CHARS = 16000;

    private ?ClientInterface $http = null;

    /** @var list<string>|null Cached, newest-first list of available doc versions. */
    private ?array $availableVersions = null;

    /**
     * Resolve the docs version to use.
     *
     * @param string|null $override Explicit `YYYY.N` version to force.
     *
     * @return array{version: string, source: string, available: list<string>, note?: string}
     *
     * @throws DocumentationException When the running Pimcore version is unsupported
     *                                or the override is invalid.
     */
    public function resolveVersion(?string $override = null): array
    {
        $major = Version::getMajorVersion();
        if ($major < self::MIN_MAJOR) {
            throw new DocumentationException(\sprintf(
                'The online platform documentation is only available for Pimcore >= %d; this instance reports major version %d.',
                self::MIN_MAJOR,
                $major,
            ));
        }

        $available = $this->availableVersions();

        if ($override !== null && $override !== '') {
            $version = $this->normalizeVersion($override);
            if ($version === null) {
                throw new DocumentationException(\sprintf('Invalid docs version "%s"; expected a "YYYY.N" value such as "2024.4".', $override));
            }
            if ($this->compareVersions($version, self::FLOOR_VERSION) < 0) {
                throw new DocumentationException(\sprintf('Docs version "%s" predates the earliest platform docs (%s).', $version, self::FLOOR_VERSION));
            }
            if ($available !== [] && !\in_array($version, $available, true)) {
                throw new DocumentationException(\sprintf(
                    'Docs version "%s" is not published. Available: %s.',
                    $version,
                    implode(', ', $available),
                ));
            }

            return ['version' => $version, 'source' => 'override', 'available' => $available];
        }

        $platform = $this->normalizeVersion(Version::getPlatformVersion() ?? '');
        if ($platform !== null && $this->compareVersions($platform, self::FLOOR_VERSION) >= 0) {
            // Prefer the exact platform version; if it isn't published, keep it
            // anyway (docs may lag/lead the package) but flag the mismatch.
            if ($available === [] || \in_array($platform, $available, true)) {
                return ['version' => $platform, 'source' => 'platform-version', 'available' => $available];
            }

            $latest = $available[0];

            return [
                'version' => $latest,
                'source' => 'platform-version-fallback',
                'available' => $available,
                'note' => \sprintf('Platform version "%s" has no published docs; using newest available "%s".', $platform, $latest),
            ];
        }

        // No usable platform version: default to the newest published set.
        if ($available !== []) {
            return [
                'version' => $available[0],
                'source' => 'auto-latest',
                'available' => $available,
                'note' => \sprintf('Could not derive a platform version from Pimcore %s; defaulting to newest docs "%s". Pass docsVersion to pin a specific set.', Version::getVersion(), $available[0]),
            ];
        }

        return [
            'version' => self::DEFAULT_VERSION,
            'source' => 'auto-default',
            'available' => [],
            'note' => \sprintf('Could not reach docs.pimcore.com to list versions; defaulting to "%s".', self::DEFAULT_VERSION),
        ];
    }

    /**
     * Fetch and parse the sidebar navigation for a version (optionally scoped to
     * a section path, which reveals that section's expanded subtree).
     *
     * @param string      $version A resolved `YYYY.N` version.
     * @param string|null $section Relative section path (e.g. "Pimcore/Objects").
     *
     * @return array{url: string, path: string, topics: list<array<string, mixed>>}
     *
     * @throws DocumentationException On network/parse failure.
     */
    public function fetchNavigation(string $version, ?string $section = null): array
    {
        $relative = $this->normalizePath($section ?? '');
        $url = $this->pageUrl($version, $relative);
        $crawler = $this->crawl($url);

        $aside = $crawler->filter('aside');
        if ($aside->count() === 0) {
            throw new DocumentationException(\sprintf('No navigation <aside> found at %s.', $url));
        }

        $rootList = $aside->filter('ul.menu__list')->first();
        $topics = $rootList->count() > 0 ? $this->parseMenu($rootList, $version) : [];

        return [
            'url' => $url,
            'path' => $relative,
            'topics' => $topics,
        ];
    }

    /**
     * Fetch a single documentation page and return its body as markdown.
     *
     * @param string $version A resolved `YYYY.N` version.
     * @param string $path    Relative page path (e.g. "Pimcore/Objects/Object_Classes").
     *
     * @return array{url: string, title: string, markdown: string, truncated: bool}
     *
     * @throws DocumentationException On network/parse failure.
     */
    public function fetchPage(string $version, string $path): array
    {
        $relative = $this->normalizePath($path);
        $url = $this->pageUrl($version, $relative);
        $crawler = $this->crawl($url);

        $article = $crawler->filter('article')->first();
        if ($article->count() === 0) {
            throw new DocumentationException(\sprintf('No <article> content found at %s (is the path correct?).', $url));
        }

        $body = $article->filter('.theme-doc-markdown')->first();
        $content = $body->count() > 0 ? $body : $article;

        $title = '';
        $h1 = $article->filter('h1')->first();
        if ($h1->count() > 0) {
            $title = trim($h1->text(''));
        }

        // Drop Docusaurus chrome that converts to noise: heading anchor links
        // ("Direct link to …") and code-block copy buttons.
        $this->removeNodes($content, 'a.hash-link, button');

        $markdown = $this->toMarkdown($content->outerHtml());
        $truncated = false;
        if (mb_strlen($markdown) > self::MAX_PAGE_CHARS) {
            $markdown = mb_substr($markdown, 0, self::MAX_PAGE_CHARS);
            $truncated = true;
        }

        return [
            'url' => $url,
            'title' => $title,
            'markdown' => $markdown,
            'truncated' => $truncated,
        ];
    }

    /**
     * Discover the published doc versions (newest first, >= floor), cached per
     * process. Returns [] if the docs site is unreachable.
     *
     * @return list<string>
     */
    public function availableVersions(): array
    {
        if ($this->availableVersions !== null) {
            return $this->availableVersions;
        }

        try {
            // The floor page is guaranteed to exist and carries the full
            // version dropdown in its markup.
            $html = $this->get(self::BASE_URL . '/platform/' . self::FLOOR_VERSION);
        } catch (DocumentationException) {
            return $this->availableVersions = [];
        }

        preg_match_all('#/platform/(\d{4}\.\d+)#', $html, $matches);
        $versions = array_values(array_unique($matches[1]));
        $versions = array_filter($versions, fn (string $v): bool => $this->compareVersions($v, self::FLOOR_VERSION) >= 0);
        usort($versions, fn (string $a, string $b): int => $this->compareVersions($b, $a));

        return $this->availableVersions = array_values($versions);
    }

    /**
     * Recursively parse a `ul.menu__list` into a nav tree.
     *
     * @return list<array<string, mixed>>
     */
    private function parseMenu(Crawler $list, string $version): array
    {
        $nodes = [];

        foreach ($list->children('li') as $liNode) {
            $li = new Crawler($liNode);

            $anchor = $li->filter('a.menu__link')->first();
            if ($anchor->count() === 0) {
                continue;
            }

            $href = $anchor->attr('href') ?? '';
            $label = $this->linkLabel($anchor);
            if ($label === '') {
                continue;
            }

            $node = [
                'title' => $label,
                'path' => $this->hrefToPath($href, $version),
            ];

            $childList = $li->children('ul.menu__list')->first();
            if ($childList->count() > 0) {
                $children = $this->parseMenu($childList, $version);
                if ($children !== []) {
                    $node['children'] = $children;
                }
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /** Detach every node matching $selector from the crawler's document. */
    private function removeNodes(Crawler $content, string $selector): void
    {
        foreach ($content->filter($selector) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function linkLabel(Crawler $anchor): string
    {
        $span = $anchor->filter('span[title]')->first();
        if ($span->count() > 0) {
            $title = $span->attr('title');
            if ($title !== null && $title !== '') {
                return trim($title);
            }
        }

        return trim($anchor->text(''));
    }

    private function toMarkdown(string $html): string
    {
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'hard_break' => true,
            'header_style' => 'atx',
            'remove_nodes' => 'script style nav',
        ]);

        return trim($converter->convert($html));
    }

    private function crawl(string $url): Crawler
    {
        return new Crawler($this->get($url), $url);
    }

    /**
     * @throws DocumentationException
     */
    private function get(string $url): string
    {
        try {
            $response = $this->client()->request('GET', $url, [
                'headers' => ['Accept' => 'text/html'],
                'http_errors' => true,
            ]);
        } catch (GuzzleException $e) {
            throw new DocumentationException(\sprintf('Failed to fetch %s: %s', $url, $e->getMessage()), 0, $e);
        }

        return (string) $response->getBody();
    }

    private function client(): ClientInterface
    {
        return $this->http ??= new Client([
            'timeout' => self::HTTP_TIMEOUT,
            'connect_timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'User-Agent' => 'pimcore-mcp-bundle documentation fetcher',
            ],
        ]);
    }

    /** Allow injecting a client (e.g. for testing). */
    public function setClient(ClientInterface $client): void
    {
        $this->http = $client;
    }

    private function pageUrl(string $version, string $relative): string
    {
        $base = self::BASE_URL . '/platform/' . $version . '/';

        return $relative === '' ? $base : $base . $relative . '/';
    }

    /**
     * Reduce an href or arbitrary path to a version-relative doc path
     * (no domain, no `/platform/<version>` prefix, no surrounding slashes).
     */
    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        // Strip scheme/host if a full URL was passed.
        $parsed = parse_url($path);
        if (isset($parsed['path'])) {
            $path = $parsed['path'];
        }

        // Drop a leading /platform/<version> segment if present.
        $path = preg_replace('#^/?platform/\d{4}\.\d+/?#', '', $path) ?? $path;

        return trim($path, '/');
    }

    private function hrefToPath(string $href, string $version): string
    {
        return $this->normalizePath($href);
    }

    /** Reduce any version-ish string to a canonical `YYYY.N`, or null. */
    private function normalizeVersion(string $version): ?string
    {
        if (preg_match('/(\d{4})\.(\d+)/', $version, $m) === 1) {
            return $m[1] . '.' . $m[2];
        }

        return null;
    }

    /** Compare two `YYYY.N` versions (returns -1/0/1). */
    private function compareVersions(string $a, string $b): int
    {
        [$ay, $an] = array_map('intval', explode('.', $a) + [1 => 0]);
        [$by, $bn] = array_map('intval', explode('.', $b) + [1 => 0]);

        return [$ay, $an] <=> [$by, $bn];
    }
}
