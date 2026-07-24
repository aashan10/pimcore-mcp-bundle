<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Extractor\DefinitionExtractor;
use Aashan\PimcoreMcpBundle\Repository\ContainerDefinitionRepository;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP tools for inspecting Pimcore field collection and object brick definitions.
 */
final class FieldContainerSchemaTool
{
    private const EXPAND_HINT = 'Fields are summarised to the essentials. Call "describe_container_field" with the '
        . 'container type, key and a field name to retrieve the field\'s complete configuration.';

    public function __construct(
        private readonly ContainerDefinitionRepository $containers,
        private readonly DefinitionExtractor $extractor,
    ) {}

    /**
     * List every field collection definition as a lightweight index.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_field_collections',
        description: 'List all Pimcore field collection definitions with basic metadata. Returns an index only — no field definitions.',
    )]
    public function listFieldCollections(): array
    {
        $items = [];
        foreach ($this->containers->allFieldCollections() as $definition) {
            $items[] = [
                'key' => $definition->getKey(),
                'title' => $definition->getTitle() !== '' ? $definition->getTitle() : null,
                'group' => $definition->getGroup(),
            ];
        }

        return [
            'count' => \count($items),
            'fieldCollections' => $items,
            '_next' => 'Call "get_field_collection" with a key to inspect its fields.',
        ];
    }

    /**
     * Retrieve the essential schema of a single field collection.
     *
     * @param string $key The field collection key, e.g. "Dimensions".
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_field_collection',
        description: 'Get the essential schema of one Pimcore field collection: its fields, layout tree and key per-field attributes.',
    )]
    public function getFieldCollection(string $key): array
    {
        $definition = $this->containers->findFieldCollection($key);
        if ($definition === null) {
            return $this->notFound('field collection', $key, 'list_field_collections');
        }

        return [
            'fieldCollection' => $this->extractor->extractFieldCollection($definition)->toArray(),
            '_note' => self::EXPAND_HINT,
        ];
    }

    /**
     * List every object brick definition as a lightweight index, including
     * which classes/fields each brick is attached to.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_object_bricks',
        description: 'List all Pimcore object brick definitions with basic metadata and the classes/fields they are attached to. Returns an index only — no field definitions.',
    )]
    public function listObjectBricks(): array
    {
        $items = [];
        foreach ($this->containers->allObjectBricks() as $definition) {
            $items[] = [
                'key' => $definition->getKey(),
                'title' => $definition->getTitle() !== '' ? $definition->getTitle() : null,
                'group' => $definition->getGroup(),
                'usedBy' => array_map(
                    static fn (array $cd): array => [
                        'class' => (string) ($cd['classname'] ?? ''),
                        'field' => (string) ($cd['fieldname'] ?? ''),
                    ],
                    $definition->getClassDefinitions(),
                ),
            ];
        }

        return [
            'count' => \count($items),
            'objectBricks' => $items,
            '_next' => 'Call "get_object_brick" with a key to inspect its fields.',
        ];
    }

    /**
     * Retrieve the essential schema of a single object brick, including the
     * classes and fields it is attached to.
     *
     * @param string $key The object brick key, e.g. "Warranty".
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_object_brick',
        description: 'Get the essential schema of one Pimcore object brick: its supported classes/fields, its fields, layout tree and key per-field attributes.',
    )]
    public function getObjectBrick(string $key): array
    {
        $definition = $this->containers->findObjectBrick($key);
        if ($definition === null) {
            return $this->notFound('object brick', $key, 'list_object_bricks');
        }

        return [
            'objectBrick' => $this->extractor->extractObjectBrick($definition)->toArray(),
            '_note' => self::EXPAND_HINT,
        ];
    }

    /**
     * Expand a single field of a field collection or object brick into its
     * complete, verbatim definition.
     *
     * @param string $containerType Either "fieldcollection" or "objectbrick".
     * @param string $key           The container key.
     * @param string $fieldName     The field's machine name.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'describe_container_field',
        description: 'Return the complete, verbatim configuration of a single field on a field collection or object brick.',
    )]
    public function describeContainerField(string $containerType, string $key, string $fieldName): array
    {
        $definition = match (strtolower($containerType)) {
            'fieldcollection', 'field_collection' => $this->containers->findFieldCollection($key),
            'objectbrick', 'object_brick' => $this->containers->findObjectBrick($key),
            default => null,
        };

        if ($definition === null) {
            return [
                'error' => \sprintf('No %s named "%s" was found.', $containerType, $key),
                '_hint' => 'containerType must be "fieldcollection" or "objectbrick".',
            ];
        }

        $field = $this->extractor->expandField($definition->getFieldDefinition($fieldName));
        if ($field === null) {
            return [
                'error' => \sprintf('Field "%s" was not found on %s "%s".', $fieldName, $containerType, $key),
            ];
        }

        return [
            'container' => ['type' => $containerType, 'key' => $key],
            'field' => $fieldName,
            'definition' => $field,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(string $type, string $key, string $listTool): array
    {
        return [
            'error' => \sprintf('No %s named "%s" was found.', $type, $key),
            '_hint' => \sprintf('Call "%s" to see the available keys.', $listTool),
        ];
    }
}
