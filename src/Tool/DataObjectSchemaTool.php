<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Extractor\DefinitionExtractor;
use Aashan\PimcoreMcpBundle\Repository\ClassDefinitionRepository;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP tools for inspecting Pimcore DataObject class definitions.
 */
final class DataObjectSchemaTool
{
    private const EXPAND_HINT = 'Fields are summarised to the essentials. Call "describe_field" with this '
        . 'class name and a field name to retrieve the field\'s complete configuration '
        . '(validators, defaults, column types, widths, tooltips, permissions, …).';

    public function __construct(
        private readonly ClassDefinitionRepository $classes,
        private readonly DefinitionExtractor $extractor,
    ) {}

    /**
     * List every DataObject class definition in the system as a lightweight
     * index (no field details). Use "get_data_object_class" to drill into one.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_data_object_classes',
        description: 'List all Pimcore DataObject class definitions with basic metadata (name, title, group, description). Returns an index only — no field definitions.',
    )]
    public function listClasses(): array
    {
        $classes = [];
        foreach ($this->classes->all() as $class) {
            $classes[] = [
                'name' => $class->getName(),
                'title' => $class->getTitle() !== '' ? $class->getTitle() : null,
                'group' => $class->getGroup(),
                'description' => $class->getDescription() !== '' ? $class->getDescription() : null,
            ];
        }

        return [
            'count' => \count($classes),
            'classes' => $classes,
            '_next' => 'Call "get_data_object_class" with a class name to inspect its fields and layout.',
        ];
    }

    /**
     * Retrieve the essential schema of a single DataObject class: its field and
     * layout tree with the most relevant attributes per field.
     *
     * @param string $className The class name, e.g. "Car" or "Customer".
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_data_object_class',
        description: 'Get the essential schema of one Pimcore DataObject class: its fields, layout tree and key per-field attributes. Field configuration is summarised; use "describe_field" to expand a single field.',
    )]
    public function getClass(string $className): array
    {
        $class = $this->classes->findByName($className);
        if ($class === null) {
            return $this->notFound($className);
        }

        return [
            'class' => $this->extractor->extractClass($class)->toArray(),
            '_note' => self::EXPAND_HINT,
        ];
    }

    /**
     * Expand a single field of a class into its complete, verbatim definition.
     *
     * @param string $className The class name, e.g. "Car".
     * @param string $fieldName The field's machine name. Localized fields are resolved too.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'describe_field',
        description: 'Return the complete, verbatim configuration of a single field on a DataObject class (all validators, defaults, widths, tooltips, permissions, column types, etc.).',
    )]
    public function describeField(string $className, string $fieldName): array
    {
        $class = $this->classes->findByName($className);
        if ($class === null) {
            return $this->notFound($className);
        }

        $definition = $this->extractor->expandField($class->getFieldDefinition($fieldName));
        if ($definition === null) {
            return [
                'error' => \sprintf('Field "%s" was not found on class "%s".', $fieldName, $className),
            ];
        }

        return [
            'class' => $className,
            'field' => $fieldName,
            'definition' => $definition,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(string $className): array
    {
        return [
            'error' => \sprintf('DataObject class "%s" was not found.', $className),
            '_hint' => 'Call "list_data_object_classes" to see the available class names.',
        ];
    }
}
