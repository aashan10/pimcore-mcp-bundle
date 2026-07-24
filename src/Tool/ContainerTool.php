<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Container\ServiceCatalog;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP tools for introspecting the Symfony service container: which services
 * exist, how they are classed and tagged, and the detail of any single one.
 *
 * Reads the debug container dump (not the live container), so tags and the
 * classes of private services are visible without instantiating anything or
 * touching the database. Requires the kernel to run in debug mode.
 */
final class ContainerTool
{
    private const MAX_LIMIT = 500;

    public function __construct(
        private readonly ServiceCatalog $catalog,
    ) {}

    /**
     * List container services, optionally filtered by tag, class or id.
     *
     * @param string|null $tag    Only services carrying this tag (e.g. "kernel.event_subscriber",
     *                            "controller.service_arguments"). Use "list_service_tags" to discover names.
     * @param string|null $class  Case-insensitive substring match on the service class — pass a
     *                            namespace prefix to scope by vendor/bundle (e.g. "Pimcore\\Bundle", "App\\").
     * @param string|null $idLike Case-insensitive substring match on the service id.
     * @param bool|null   $public Filter by visibility: true = only public, false = only private, null = both.
     * @param int         $limit  Max results (default 100, max 500).
     * @param int         $offset Results to skip (paging).
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_container_services',
        description: 'List Symfony service-container services, filtered by tag, class (substring / namespace prefix), id substring and visibility. Each entry has id, class, public and its tag names. Reads the debug container dump (no DB, no instantiation).',
    )]
    public function listContainerServices(
        ?string $tag = null,
        ?string $class = null,
        ?string $idLike = null,
        ?bool $public = null,
        int $limit = 100,
        int $offset = 0,
    ): array {
        if (!$this->catalog->isAvailable()) {
            return $this->unavailable();
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);

        $result = $this->catalog->services([
            'tag' => $tag,
            'class' => $class,
            'idLike' => $idLike,
            'public' => $public,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return [
            'total' => $result['total'],
            'count' => $result['count'],
            'offset' => $offset,
            'filters' => array_filter(
                ['tag' => $tag, 'class' => $class, 'idLike' => $idLike, 'public' => $public],
                static fn (mixed $v): bool => $v !== null,
            ),
            'services' => $result['services'],
            '_note' => 'Only tag names are listed here; call "describe_service" with an id for the full definition (tag attributes, factory, method calls, aliases).',
        ];
    }

    /**
     * List every service tag in the container with how many services carry it.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_service_tags',
        description: 'List all service tags present in the container with an occurrence count each. Use these tag names to filter "list_container_services".',
    )]
    public function listServiceTags(): array
    {
        if (!$this->catalog->isAvailable()) {
            return $this->unavailable();
        }

        $tags = $this->catalog->tags();

        return [
            'count' => \count($tags),
            'tags' => $tags,
        ];
    }

    /**
     * Full definition of a single service (or alias) by id.
     *
     * @param string $id The service id, e.g. a FQCN or a named service like "logger".
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'describe_service',
        description: 'Describe one container service (or alias) by id: class, visibility, shared/abstract/lazy/synthetic/deprecated flags, factory, tags (with attributes), method calls and aliases.',
    )]
    public function describeService(string $id): array
    {
        if (!$this->catalog->isAvailable()) {
            return $this->unavailable();
        }

        $service = $this->catalog->describe($id);
        if ($service === null) {
            return [
                'error' => \sprintf('No service or alias with id "%s".', $id),
                '_hint' => 'Ids are case-sensitive. Use "list_container_services" (e.g. with idLike) to find the exact id.',
            ];
        }

        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(): array
    {
        return [
            'error' => 'Container introspection is unavailable.',
            '_hint' => 'It reads the debug container dump, which only exists when the kernel runs in debug mode (APP_ENV=dev or APP_DEBUG=1). Warm the cache and retry.',
        ];
    }
}
