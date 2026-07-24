<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Repository\ElementRepository;
use Aashan\PimcoreMcpBundle\Serializer\ElementSerializer;
use Mcp\Capability\Attribute\McpTool;
use Pimcore\Model\Element\ElementInterface;

/**
 * MCP tools for inspecting Pimcore content elements: documents, assets and
 * data objects (their tree and individual element data).
 */
final class ElementInspectionTool
{
    public function __construct(
        private readonly ElementRepository $elements,
        private readonly ElementSerializer $serializer,
    ) {}

    /**
     * List the direct children of a document/asset/data-object tree node.
     * Use the root ("/") or a folder to browse the tree top-down.
     *
     * @param string      $elementType One of: document, asset, object.
     * @param string|null $path        Parent path (e.g. "/" or "/products"). Used when $id is omitted.
     * @param int|null    $id          Parent element id (takes precedence over $path).
     * @param bool        $includeUnpublished Include unpublished documents/objects (ignored for assets).
     * @param int         $limit       Max children to return (default 100).
     * @param int         $offset      Children to skip (pagination).
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_children',
        description: 'List the direct children of a Pimcore document, asset or data object tree node (by parent path or id). Returns lightweight summaries for tree navigation.',
    )]
    public function listChildren(
        string $elementType,
        ?string $path = null,
        ?int $id = null,
        bool $includeUnpublished = false,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $type = $this->elements->normalizeType($elementType);
        if ($type === null) {
            return $this->invalidType();
        }

        // Default to the tree root when neither id nor path is given.
        if ($id === null && ($path === null || $path === '')) {
            $path = '/';
        }

        $parent = $this->elements->resolve($type, $id, $path);
        if ($parent === null) {
            return ['error' => \sprintf('%s parent not found (id=%s, path=%s).', $type, $id ?? 'null', $path ?? 'null')];
        }

        $result = $this->elements->children($type, (int) $parent->getId(), $includeUnpublished, $limit, $offset);

        return [
            'parent' => $this->serializer->summary($parent),
            'total' => $result['total'],
            'count' => \count($result['items']),
            'offset' => $offset,
            'children' => array_map(
                fn (ElementInterface $child): array => $this->serializer->summary($child),
                $result['items'],
            ),
            '_next' => 'Call "get_element" with an id/path to inspect one element, or "list_children" on a child to go deeper.',
        ];
    }

    /**
     * Inspect a single element in full: its metadata plus type-specific content
     * (data object field values, document editables, or asset metadata).
     *
     * @param string      $elementType One of: document, asset, object.
     * @param int|null    $id          Element id (takes precedence over $path).
     * @param string|null $path        Element path (used when $id is omitted).
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_element',
        description: 'Inspect one Pimcore element (document, asset or data object) by id or path: metadata, properties, and type-specific content (data object field values, document editables, asset metadata). Referenced elements are returned as {type,id,path} references.',
    )]
    public function getElement(string $elementType, ?int $id = null, ?string $path = null): array
    {
        $type = $this->elements->normalizeType($elementType);
        if ($type === null) {
            return $this->invalidType();
        }

        $element = $this->elements->resolve($type, $id, $path);
        if ($element === null) {
            return ['error' => \sprintf('%s not found (id=%s, path=%s).', $type, $id ?? 'null', $path ?? 'null')];
        }

        return ['element' => $this->serializer->detail($element)];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidType(): array
    {
        return [
            'error' => \sprintf('Invalid elementType. Allowed: %s.', implode(', ', ElementRepository::TYPES)),
        ];
    }
}
