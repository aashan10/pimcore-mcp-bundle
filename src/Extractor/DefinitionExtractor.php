<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Extractor;

use Aashan\PimcoreMcpBundle\Entity\Definitions\ClassDefinition as ClassDefinitionDto;
use Aashan\PimcoreMcpBundle\Entity\Definitions\ContainerDefinition;
use Aashan\PimcoreMcpBundle\Entity\Definitions\ContainerType;
use Aashan\PimcoreMcpBundle\Entity\Definitions\FieldDefinition;
use Aashan\PimcoreMcpBundle\Entity\Definitions\FieldType;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Data\Relations\AbstractRelations;
use Pimcore\Model\DataObject\ClassDefinition\Layout;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Objectbrick;

/**
 * Projects verbose Pimcore definition objects into the compact DTOs this
 * bundle exposes over MCP.
 *
 * The guiding principle is *essential information only*: enough for an agent to
 * understand the schema and generate correct code, without the full editor
 * configuration. The complete, verbatim field configuration is produced
 * separately by {@see expandField()} and surfaced through the `describe_field`
 * tool.
 */
final class DefinitionExtractor
{
    public function extractClass(ClassDefinition $class): ClassDefinitionDto
    {
        return new ClassDefinitionDto(
            id: (string) $class->getId(),
            name: (string) $class->getName(),
            title: $class->getTitle() !== '' ? $class->getTitle() : null,
            group: $class->getGroup(),
            description: $class->getDescription() !== '' ? $class->getDescription() : null,
            allowInherit: $class->getAllowInherit(),
            allowVariants: $class->getAllowVariants(),
            fields: $this->extractTree($class->getLayoutDefinitions()),
        );
    }

    public function extractFieldCollection(Fieldcollection\Definition $definition): ContainerDefinition
    {
        return new ContainerDefinition(
            key: (string) $definition->getKey(),
            type: ContainerType::FIELD_COLLECTION,
            title: $definition->getTitle() !== '' ? $definition->getTitle() : null,
            group: $definition->getGroup(),
            usedBy: [],
            fields: $this->extractTree($definition->getLayoutDefinitions()),
        );
    }

    public function extractObjectBrick(Objectbrick\Definition $definition): ContainerDefinition
    {
        $usedBy = array_map(
            static fn (array $classDefinition): array => [
                'class' => (string) ($classDefinition['classname'] ?? ''),
                'field' => (string) ($classDefinition['fieldname'] ?? ''),
            ],
            $definition->getClassDefinitions(),
        );

        return new ContainerDefinition(
            key: (string) $definition->getKey(),
            type: ContainerType::OBJECT_BRICK,
            title: $definition->getTitle() !== '' ? $definition->getTitle() : null,
            group: $definition->getGroup(),
            usedBy: $usedBy,
            fields: $this->extractTree($definition->getLayoutDefinitions()),
        );
    }

    /**
     * The complete, verbatim configuration of a single data field.
     *
     * @return array<string, mixed>|null
     */
    public function expandField(?Data $field): ?array
    {
        if ($field === null) {
            return null;
        }

        // jsonSerialize() returns every public property of the field definition
        // (validators, defaults, widths, tooltips, permissions, column types, …).
        $data = $field->jsonSerialize();

        return \is_array($data) ? $data : null;
    }

    /**
     * Extract the essential field/layout tree starting from a layout root.
     *
     * The root layout element is the implicit outermost container (usually a
     * panel); we return its children directly so the output isn't wrapped in a
     * meaningless top-level node.
     *
     * @return FieldDefinition[]
     */
    private function extractTree(?Layout $root): array
    {
        if ($root === null) {
            return [];
        }

        return $this->extractNodes($root->getChildren());
    }

    /**
     * @param array<int, Data|Layout> $nodes
     *
     * @return FieldDefinition[]
     */
    private function extractNodes(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof Layout || $node instanceof Data) {
                $result[] = $this->extractNode($node);
            }
        }

        return $result;
    }

    private function extractNode(Data|Layout $node): FieldDefinition
    {
        if ($node instanceof Layout) {
            return new FieldDefinition(
                name: $node->getName() ?? '',
                kind: FieldType::LAYOUT,
                fieldtype: $node->getType() ?? 'layout',
                title: $node->getTitle(),
                attributes: [],
                children: $this->extractNodes($node->getChildren()),
                // Layout nodes hold only cosmetic config — nothing worth expanding.
                expandable: false,
            );
        }

        // Container data types (localizedfields, block, classificationstore)
        // carry their own nested field tree.
        $children = [];
        if (method_exists($node, 'getChildren')) {
            $children = $this->extractNodes($node->getChildren());
        }

        $title = $node->getTitle();

        return new FieldDefinition(
            name: $node->getName() ?? '',
            kind: FieldType::DATA,
            fieldtype: $node->getFieldType(),
            title: $title !== '' ? $title : null,
            attributes: $this->essentialAttributes($node),
            children: $children,
        );
    }

    /**
     * A curated set of type-specific highlights that materially affect how a
     * developer would use the field. Everything else stays behind `describe_field`.
     *
     * @return array<string, mixed>
     */
    private function essentialAttributes(Data $field): array
    {
        $attributes = [];

        if ($field->getMandatory()) {
            $attributes['mandatory'] = true;
        }
        if ($field->getNoteditable()) {
            $attributes['readOnly'] = true;
        }
        if ($field->getInvisible()) {
            $attributes['invisible'] = true;
        }

        // Relations: the allowed target classes are essential for typing getters.
        if ($field instanceof AbstractRelations) {
            $classes = array_values(array_filter(array_map(
                static fn (array $entry): ?string => $entry['classes'] ?? null,
                $field->getClasses(),
            )));
            if ($classes !== []) {
                $attributes['allowedClasses'] = $classes;
            }
        }

        // Select-like fields: static options, or the dynamic options provider.
        if (method_exists($field, 'getOptionsProviderClass') && $field->getOptionsProviderClass()) {
            $attributes['optionsProvider'] = $field->getOptionsProviderClass();
        } elseif (method_exists($field, 'getOptions')) {
            $options = $field->getOptions();
            if (\is_array($options) && $options !== []) {
                $attributes['options'] = array_map(
                    static fn (mixed $option): mixed => \is_array($option) ? ($option['value'] ?? $option) : $option,
                    $options,
                );
            }
        }

        // Field collections / object bricks: the allowed sub-definitions.
        if (method_exists($field, 'getAllowedTypes')) {
            $allowedTypes = $field->getAllowedTypes();
            if (\is_array($allowedTypes) && $allowedTypes !== []) {
                $attributes['allowedTypes'] = array_values($allowedTypes);
            }
        }

        if (method_exists($field, 'getMaxLength') && ($maxLength = $field->getMaxLength())) {
            $attributes['maxLength'] = $maxLength;
        }
        if (method_exists($field, 'getUnique') && $field->getUnique()) {
            $attributes['unique'] = true;
        }

        return $attributes;
    }
}
