<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Entity\Definitions;

use Aashan\PimcoreMcpBundle\Entity\EntityInterface;

/**
 * A single parameter of a public method on a generated Pimcore model class.
 */
final readonly class ParameterDefinition implements EntityInterface
{
    public function __construct(
        public string $name,
        public ?string $type = null,
        public bool $optional = false,
        public bool $variadic = false,
        public bool $hasDefault = false,
        public mixed $default = null,
    ) {}

    public function getId(): mixed
    {
        return $this->name;
    }

    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'type' => $this->type,
        ];

        if ($this->optional) {
            $data['optional'] = true;
        }
        if ($this->variadic) {
            $data['variadic'] = true;
        }
        if ($this->hasDefault) {
            $data['default'] = $this->default;
        }

        return $data;
    }
}
