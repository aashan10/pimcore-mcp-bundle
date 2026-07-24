<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Entity\Definitions;

use Aashan\PimcoreMcpBundle\Entity\EntityInterface;

/**
 * A public method available on a generated Pimcore model class, with a rendered
 * PHP signature so an agent can call it correctly.
 */
final readonly class MethodDefinition implements EntityInterface
{
    /**
     * @param ParameterDefinition[] $parameters
     * @param string|null           $field      The data field this accessor maps to (getX/setX/isX), if any.
     */
    public function __construct(
        public string $name,
        public string $signature,
        public ?string $returnType,
        public bool $static = false,
        public array $parameters = [],
        public ?string $field = null,
    ) {}

    public function getId(): mixed
    {
        return $this->name;
    }

    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'signature' => $this->signature,
            'returnType' => $this->returnType,
        ];

        if ($this->static) {
            $data['static'] = true;
        }
        if ($this->field !== null) {
            $data['field'] = $this->field;
        }
        if ($this->parameters !== []) {
            $data['parameters'] = array_map(
                static fn (ParameterDefinition $parameter): array => $parameter->toArray(),
                $this->parameters,
            );
        }

        return $data;
    }
}
