<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Repository;

use Pimcore\Model\DataObject\ClassDefinition;

/**
 * Access layer over Pimcore's DataObject class definitions.
 *
 * Definitions are read straight from their generated definition files
 * (`PIMCORE_CLASS_DEFINITION_DIRECTORY/definition_<Name>.php`), which each
 * `return` a fully reconstructed {@see ClassDefinition} via `__set_state()`.
 * This keeps the tool usable for pure schema introspection without a live
 * database — mirroring how field collections and object bricks are loaded.
 */
final class ClassDefinitionRepository
{
    /**
     * @return ClassDefinition[]
     */
    public function all(): array
    {
        $classes = [];
        foreach ($this->definitionFiles() as $file) {
            $class = @include $file;
            if ($class instanceof ClassDefinition) {
                $classes[] = $class;
            }
        }

        usort(
            $classes,
            static fn (ClassDefinition $a, ClassDefinition $b): int => strcmp((string) $a->getName(), (string) $b->getName()),
        );

        return $classes;
    }

    public function findByName(string $name): ?ClassDefinition
    {
        $file = $this->directory() . '/definition_' . $name . '.php';
        if (!is_file($file)) {
            return null;
        }

        $class = @include $file;

        return $class instanceof ClassDefinition ? $class : null;
    }

    private function directory(): string
    {
        return \PIMCORE_CLASS_DEFINITION_DIRECTORY;
    }

    /**
     * @return string[]
     */
    private function definitionFiles(): array
    {
        return glob($this->directory() . '/definition_*.php') ?: [];
    }
}
