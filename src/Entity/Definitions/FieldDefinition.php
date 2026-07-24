<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Entity\Definitions;

use Aashan\PimcoreMcpBundle\Entity\EntityInterface;

/**
 * A single node in a definition tree.
 *
 * This is the *essential* projection of a Pimcore field/layout element — just
 * enough for an agent to understand the schema without drowning in the full
 * editor configuration. The complete, verbatim definition (validators,
 * tooltips, widths, permissions, defaults, …) is available on demand via the
 * {@see $expandable} hint and the `describe_field` tool.
 */
final readonly class FieldDefinition implements EntityInterface
{
    /**
     * @param string               $name       Machine name (the getter/setter key for data fields).
     * @param FieldType             $kind       Whether this node carries data or is purely structural.
     * @param string               $fieldtype  Pimcore field type, e.g. "input", "localizedfields",
     *                                          "manyToOneRelation", "panel", "tabpanel".
     * @param string|null          $title      Human readable label shown in the editor.
     * @param array<string, mixed> $attributes Curated, type-specific highlights (mandatory, options,
     *                                          allowed relation classes, …). Never the full config.
     * @param FieldDefinition[]    $children   Nested nodes (layout children, or the sub-fields of
     *                                          container data types such as localizedfields/block/bricks).
     * @param bool                 $expandable Whether a fuller definition exists behind `describe_field`.
     */
    public function __construct(
        public string $name,
        public FieldType $kind,
        public string $fieldtype,
        public ?string $title = null,
        public array $attributes = [],
        public array $children = [],
        public bool $expandable = true,
    ) {}

    public function getId(): mixed
    {
        return $this->name;
    }

    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'kind' => $this->kind->value,
            'fieldtype' => $this->fieldtype,
        ];

        if ($this->title !== null && $this->title !== '') {
            $data['title'] = $this->title;
        }

        if ($this->attributes !== []) {
            $data['attributes'] = $this->attributes;
        }

        if ($this->children !== []) {
            $data['children'] = array_map(
                static fn (FieldDefinition $child): array => $child->toArray(),
                $this->children,
            );
        }

        return $data;
    }
}
