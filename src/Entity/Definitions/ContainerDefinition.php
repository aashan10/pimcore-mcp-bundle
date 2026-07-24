<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Entity\Definitions;

use Aashan\PimcoreMcpBundle\Entity\EntityInterface;

/**
 * Essential projection of a field collection or object brick definition.
 *
 * Both share the same shape in Pimcore (they extend the same definition trait),
 * so a single DTO covers them, discriminated by {@see $type}.
 *
 * @see \Pimcore\Model\DataObject\Fieldcollection\Definition
 * @see \Pimcore\Model\DataObject\Objectbrick\Definition
 */
final readonly class ContainerDefinition implements EntityInterface
{
    /**
     * @param string             $key         Definition key (also the generated PHP type name).
     * @param ContainerType      $type        Field collection or object brick.
     * @param string|null        $title       Optional display title.
     * @param string|null        $group       Optional grouping used in the tree.
     * @param list<array{class: string, field: string}> $usedBy Classes/fields this brick is attached to.
     *                                          Always empty for field collections (they are referenced
     *                                          per-field and not tracked on the definition itself).
     * @param FieldDefinition[]  $fields      The flattened, essential field/layout tree.
     */
    public function __construct(
        public string $key,
        public ContainerType $type,
        public ?string $title = null,
        public ?string $group = null,
        public array $usedBy = [],
        public array $fields = [],
    ) {}

    public function getId(): mixed
    {
        return $this->key;
    }

    public function toArray(): array
    {
        $data = [
            'key' => $this->key,
            'type' => $this->type->value,
            'title' => $this->title,
            'group' => $this->group,
        ];

        if ($this->type === ContainerType::OBJECT_BRICK) {
            $data['usedBy'] = $this->usedBy;
        }

        $data['fields'] = array_map(
            static fn (FieldDefinition $field): array => $field->toArray(),
            $this->fields,
        );

        return $data;
    }
}
