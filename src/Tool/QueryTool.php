<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Repository\QueryRepository;
use Aashan\PimcoreMcpBundle\Serializer\ElementSerializer;
use Mcp\Capability\Attribute\McpTool;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\ElementInterface;

/**
 * MCP tools for locating Pimcore content: data objects by class + field
 * conditions, and documents/assets by path/type/name.
 */
final class QueryTool
{
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly QueryRepository $query,
        private readonly ElementSerializer $serializer,
    ) {}

    /**
     * Find data objects of a class matching field conditions.
     *
     * @param string      $className          Class name, e.g. "Car".
     * @param string|null $filters            JSON array of conditions, e.g.
     *                                         [{"field":"carClass","operator":"=","value":"sports car"},
     *                                          {"field":"productionYear","operator":">=","value":1960}].
     *                                         Operators: =, !=, >, >=, <, <=, LIKE, NOT LIKE, IN, NOT IN, IS NULL, IS NOT NULL.
     *                                         Fields must be class fields or system fields (id, key, published, path, …).
     * @param string|null $pathPrefix         Only objects under this tree path (e.g. "/Product Data/Cars").
     * @param string|null $fields             JSON array of field names to include in each result (value preview).
     * @param bool        $includeUnpublished Include unpublished objects.
     * @param string|null $orderBy            Field to sort by.
     * @param string      $orderDir           "ASC" or "DESC".
     * @param int         $limit              Max results (default 50, max 200).
     * @param int         $offset             Results to skip.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'find_objects',
        description: 'Find Pimcore data objects of a class by field conditions (safe, validated filters), with optional field-value previews, path scoping, sorting and paging.',
    )]
    public function findObjects(
        string $className,
        ?string $filters = null,
        ?string $pathPrefix = null,
        ?string $fields = null,
        bool $includeUnpublished = false,
        ?string $orderBy = null,
        string $orderDir = 'ASC',
        int $limit = 50,
        int $offset = 0,
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $decodedFilters = $this->decodeArray($filters);
        if ($decodedFilters === false) {
            return ['error' => 'The "filters" argument must be a JSON array of {field, operator, value} objects.'];
        }
        $previewFields = $this->decodeArray($fields);
        if ($previewFields === false) {
            return ['error' => 'The "fields" argument must be a JSON array of field names.'];
        }

        try {
            $result = $this->query->findObjects(
                $className,
                $decodedFilters,
                $pathPrefix,
                $includeUnpublished,
                $orderBy,
                $orderDir,
                $limit,
                $offset,
            );
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => \sprintf('Query failed: %s', $e->getMessage())];
        }

        $objects = array_map(function (Concrete $object) use ($previewFields): array {
            $row = $this->serializer->summary($object);
            if ($previewFields !== []) {
                $row['fields'] = $this->serializer->fieldPreview($object, array_map('strval', $previewFields));
            }

            return $row;
        }, $result['items']);

        return [
            'className' => $className,
            'total' => $result['total'],
            'count' => \count($objects),
            'offset' => $offset,
            'objects' => $objects,
            '_note' => 'Use "get_element" (elementType=object) with an id for full field values.',
        ];
    }

    /**
     * Find documents or assets by path, type and name.
     *
     * @param string      $elementType        "document" or "asset".
     * @param string|null $pathPrefix         Only elements under this tree path (e.g. "/products").
     * @param string|null $type               Node type filter (documents: page/snippet/link/folder/…; assets: image/video/document/folder/…).
     * @param string|null $nameLike           Substring match on document key / asset filename.
     * @param bool        $includeUnpublished Include unpublished documents (ignored for assets).
     * @param int         $limit              Max results (default 50, max 200).
     * @param int         $offset             Results to skip.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'find_elements',
        description: 'Find Pimcore documents or assets by path prefix, node type and name substring. Returns lightweight summaries.',
    )]
    public function findElements(
        string $elementType,
        ?string $pathPrefix = null,
        ?string $type = null,
        ?string $nameLike = null,
        bool $includeUnpublished = false,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $normalized = match (strtolower($elementType)) {
            'document', 'documents', 'doc' => 'document',
            'asset', 'assets' => 'asset',
            default => null,
        };
        if ($normalized === null) {
            return ['error' => 'elementType must be "document" or "asset" (use find_objects for data objects).'];
        }

        $result = $this->query->findElements($normalized, $pathPrefix, $type, $nameLike, $includeUnpublished, $limit, $offset);

        return [
            'elementType' => $normalized,
            'total' => $result['total'],
            'count' => \count($result['items']),
            'offset' => $offset,
            'elements' => array_map(
                fn (ElementInterface $e): array => $this->serializer->summary($e),
                $result['items'],
            ),
        ];
    }

    /**
     * @return array<mixed>|false Decoded array, [] when null/empty, or false on invalid JSON.
     */
    private function decodeArray(?string $json): array|false
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : false;
    }
}
