<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Extractor;

use Aashan\PimcoreMcpBundle\Entity\Definitions\ClassApiDefinition;
use Aashan\PimcoreMcpBundle\Entity\Definitions\MethodDefinition;
use Aashan\PimcoreMcpBundle\Entity\Definitions\ParameterDefinition;

/**
 * Reflects the *generated* Pimcore model classes to expose their public PHP API
 * (the field getters/setters and other class-specific methods, with real type
 * hints) so agents can write correct code against them.
 *
 * Methods inherited from Pimcore's framework base classes (Concrete,
 * AbstractObject, AbstractData, …) are intentionally omitted: they are the
 * standard, documented model API (save/delete/getById/getList/…), not the
 * schema-specific surface a developer needs help with. The base class is
 * reported via {@see ClassApiDefinition::$extends} instead.
 */
final class ClassApiReflector
{
    private const DATA_OBJECT_NAMESPACE = 'Pimcore\\Model\\DataObject\\';

    /**
     * Framework base classes at which the method walk stops.
     */
    private const BASE_CLASSES = [
        'Pimcore\\Model\\DataObject\\Concrete',
        'Pimcore\\Model\\DataObject\\AbstractObject',
        'Pimcore\\Model\\AbstractModel',
        'Pimcore\\Model\\Element\\AbstractElement',
        'Pimcore\\Model\\DataObject\\Objectbrick\\Data\\AbstractData',
        'Pimcore\\Model\\DataObject\\Fieldcollection\\Data\\AbstractData',
    ];

    public function reflectClass(string $className): ?ClassApiDefinition
    {
        return $this->reflect($className, self::DATA_OBJECT_NAMESPACE . $className);
    }

    public function reflectObjectBrick(string $key): ?ClassApiDefinition
    {
        return $this->reflect($key, self::DATA_OBJECT_NAMESPACE . 'Objectbrick\\Data\\' . $key);
    }

    public function reflectFieldCollection(string $key): ?ClassApiDefinition
    {
        return $this->reflect($key, self::DATA_OBJECT_NAMESPACE . 'Fieldcollection\\Data\\' . $key);
    }

    private function reflect(string $name, string $fqcn): ?ClassApiDefinition
    {
        if (!class_exists($fqcn)) {
            return null;
        }

        $reflection = new \ReflectionClass($fqcn);

        $parent = $reflection->getParentClass();

        return new ClassApiDefinition(
            name: $name,
            fqcn: '\\' . ltrim($fqcn, '\\'),
            extends: $parent !== false ? '\\' . $parent->getName() : null,
            implements: array_map(static fn (string $i): string => '\\' . $i, $reflection->getInterfaceNames()),
            methods: $this->collectMethods($reflection),
        );
    }

    /**
     * @return MethodDefinition[]
     */
    private function collectMethods(\ReflectionClass $reflection): array
    {
        $methods = [];

        for ($class = $reflection; $class instanceof \ReflectionClass; $class = $class->getParentClass() ?: null) {
            if (\in_array($class->getName(), self::BASE_CLASSES, true)) {
                break;
            }

            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                // Only methods actually declared on this level, and skip magic methods.
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                if (str_starts_with($method->getName(), '__')) {
                    continue;
                }
                // First occurrence wins: the most-derived override is kept.
                if (isset($methods[$method->getName()])) {
                    continue;
                }

                $methods[$method->getName()] = $this->describeMethod($method);
            }
        }

        $result = array_values($methods);
        usort($result, static fn (MethodDefinition $a, MethodDefinition $b): int => strcmp($a->name, $b->name));

        return $result;
    }

    private function describeMethod(\ReflectionMethod $method): MethodDefinition
    {
        $parameters = array_map(
            fn (\ReflectionParameter $parameter): ParameterDefinition => $this->describeParameter($parameter),
            $method->getParameters(),
        );

        $returnType = $this->renderType($method->getReturnType());

        return new MethodDefinition(
            name: $method->getName(),
            signature: $this->renderSignature($method, $parameters, $returnType),
            returnType: $returnType,
            static: $method->isStatic(),
            parameters: $parameters,
            field: $this->fieldForAccessor($method->getName()),
        );
    }

    private function describeParameter(\ReflectionParameter $parameter): ParameterDefinition
    {
        $hasDefault = $parameter->isDefaultValueAvailable();
        $default = null;
        if ($hasDefault) {
            $value = $parameter->getDefaultValue();
            // Keep only JSON-friendly defaults; anything else is signalled via `optional`.
            if ($value === null || \is_scalar($value) || \is_array($value)) {
                $default = $value;
            } else {
                $hasDefault = false;
            }
        }

        return new ParameterDefinition(
            name: $parameter->getName(),
            type: $this->renderType($parameter->getType()),
            optional: $parameter->isOptional(),
            variadic: $parameter->isVariadic(),
            hasDefault: $hasDefault,
            default: $default,
        );
    }

    /**
     * @param ParameterDefinition[] $parameters
     */
    private function renderSignature(\ReflectionMethod $method, array $parameters, ?string $returnType): string
    {
        $parts = [];
        foreach ($parameters as $parameter) {
            $piece = '';
            if ($parameter->type !== null) {
                $piece .= $parameter->type . ' ';
            }
            $piece .= ($parameter->variadic ? '...' : '') . '$' . $parameter->name;
            if ($parameter->hasDefault) {
                $piece .= ' = ' . $this->renderLiteral($parameter->default);
            }
            $parts[] = $piece;
        }

        $prefix = $method->isStatic() ? 'static ' : '';
        $signature = $prefix . $method->getName() . '(' . implode(', ', $parts) . ')';
        if ($returnType !== null) {
            $signature .= ': ' . $returnType;
        }

        return $signature;
    }

    private function renderType(?\ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            $isClass = !$type->isBuiltin() && !\in_array($name, ['self', 'static', 'parent'], true);
            $rendered = $isClass ? '\\' . $name : $name;

            if ($type->allowsNull() && $name !== 'null' && $name !== 'mixed') {
                return '?' . $rendered;
            }

            return $rendered;
        }

        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(fn (\ReflectionType $t): string => (string) $this->renderType($t), $type->getTypes()));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(fn (\ReflectionType $t): string => (string) $this->renderType($t), $type->getTypes()));
        }

        return (string) $type;
    }

    private function renderLiteral(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => "'" . $value . "'",
            \is_array($value) => $value === [] ? '[]' : json_encode($value),
            default => (string) $value,
        };
    }

    private function fieldForAccessor(string $method): ?string
    {
        if (preg_match('/^(get|set|is|has)([A-Z].*)$/', $method, $matches) === 1) {
            return lcfirst($matches[2]);
        }

        return null;
    }
}
