<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Entity\Definitions;

use Aashan\PimcoreMcpBundle\Entity\EntityInterface;

/**
 * Essential projection of a Pimcore DataObject class definition.
 *
 * @see \Pimcore\Model\DataObject\ClassDefinition
 */
final readonly class ClassDefinition implements EntityInterface
{
    /**
     * @param string            $id          Class definition id.
     * @param string            $name        Class name (also the generated PHP model name).
     * @param string|null       $title       Optional display title.
     * @param string|null       $group       Optional grouping used in the class tree.
     * @param string|null       $description Optional description.
     * @param bool              $allowInherit Whether objects of this class inherit parent values.
     * @param bool              $allowVariants Whether variants are enabled.
     * @param FieldDefinition[] $fields      The flattened, essential field/layout tree.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $title = null,
        public ?string $group = null,
        public ?string $description = null,
        public bool $allowInherit = false,
        public bool $allowVariants = false,
        public array $fields = [],
    ) {}

    public function getId(): mixed
    {
        return $this->id;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'group' => $this->group,
            'description' => $this->description,
            'allowInherit' => $this->allowInherit,
            'allowVariants' => $this->allowVariants,
            'fields' => array_map(
                static fn (FieldDefinition $field): array => $field->toArray(),
                $this->fields,
            ),
        ];
    }
}
