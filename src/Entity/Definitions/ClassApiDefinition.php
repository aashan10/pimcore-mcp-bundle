<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Entity\Definitions;

use Aashan\PimcoreMcpBundle\Entity\EntityInterface;

/**
 * The public PHP API of a generated Pimcore model class (a DataObject concrete
 * class, an object brick data class or a field collection data class).
 */
final readonly class ClassApiDefinition implements EntityInterface
{
    /**
     * @param string             $name       Short name (class / brick / collection key).
     * @param string             $fqcn       Fully qualified class name to type-hint / instantiate against.
     * @param string|null        $extends    Parent class FQCN.
     * @param string[]           $implements Implemented interface FQCNs.
     * @param MethodDefinition[] $methods    Field accessors and other class-specific public methods.
     */
    public function __construct(
        public string $name,
        public string $fqcn,
        public ?string $extends = null,
        public array $implements = [],
        public array $methods = [],
    ) {}

    public function getId(): mixed
    {
        return $this->fqcn;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'fqcn' => $this->fqcn,
            'extends' => $this->extends,
            'implements' => $this->implements,
            'methods' => array_map(
                static fn (MethodDefinition $method): array => $method->toArray(),
                $this->methods,
            ),
        ];
    }
}
