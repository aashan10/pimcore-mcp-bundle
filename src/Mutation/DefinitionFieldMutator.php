<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Mutation;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Layout;
use Pimcore\Model\DataObject\ClassDefinition\Service;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Objectbrick;

/**
 * Creates and edits DataObject class / field collection / object brick
 * definitions (create, add/update/remove field, and class JSON import/export)
 * and persists them.
 *
 * Fields are built with Pimcore's own {@see Service::generateLayoutTreeFromArray()}
 * (the exact mechanism the admin UI uses), then grafted into the definition's
 * layout tree and saved. Saving regenerates the PHP model classes and updates
 * the database tables — so these are real, persistent schema changes and need a
 * working DB plus a writable class-definition directory.
 */
final class DefinitionFieldMutator
{
    public const SCOPES = ['class', 'fieldcollection', 'objectbrick'];

    /**
     * @param array{name: string, fieldtype: string, title?: ?string, mandatory?: bool, config?: array<string, mixed>, parent?: ?string} $spec
     *
     * @return ClassDefinition|Fieldcollection\Definition|Objectbrick\Definition
     *
     * @throws \InvalidArgumentException on user error (unknown container, duplicate/invalid field, bad parent, unsupported type)
     */
    public function addField(string $scope, string $container, array $spec): object
    {
        $definition = $this->resolveDefinition($scope, $container);

        $name = $spec['name'];
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid field name "%s". Use a letter followed by letters, digits or underscores.', $name),
            );
        }
        if ($definition->getFieldDefinition($name) !== null) {
            throw new \InvalidArgumentException(\sprintf('A field named "%s" already exists on %s "%s".', $name, $scope, $container));
        }

        // throwException=false so an unknown fieldtype / bad config yields a
        // clean message here instead of Pimcore's raw exception dump.
        $field = Service::generateLayoutTreeFromArray($this->buildNode($spec), false);
        if (!$field instanceof Data) {
            throw new \InvalidArgumentException(
                \sprintf('Unsupported fieldtype "%s", or invalid field config.', $spec['fieldtype']),
            );
        }

        $root = $definition->getLayoutDefinitions();
        if (!$root instanceof Layout) {
            $root = $this->newRootLayout();
        }

        $parentName = $spec['parent'] ?? null;
        if ($parentName !== null && $parentName !== '') {
            $target = $this->findContainer($root, $parentName);
            if ($target === null) {
                throw new \InvalidArgumentException(\sprintf('Parent container "%s" was not found in the layout tree.', $parentName));
            }
        } else {
            $target = $root;
        }

        $target->addChild($field);

        $definition->setLayoutDefinitions($root);
        $definition->save();

        return $definition;
    }

    /**
     * Create a new class / field collection / object brick, optionally with fields.
     *
     * @param list<array{name: string, fieldtype: string, title?: ?string, mandatory?: bool, config?: array<string, mixed>}> $fieldSpecs
     * @param array<string, mixed> $options
     *
     * @return ClassDefinition|Fieldcollection\Definition|Objectbrick\Definition
     *
     * @throws \InvalidArgumentException
     */
    public function createDefinition(string $scope, string $name, array $fieldSpecs, array $options): object
    {
        if (!\in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid scope "%s". Allowed: %s.', $scope, implode(', ', self::SCOPES)));
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid name "%s". Use a letter followed by letters/digits (PascalCase recommended).', $name));
        }
        $this->assertNotExists($scope, $name);

        $root = $this->buildRootLayout($fieldSpecs);

        switch ($scope) {
            case 'class':
                $definition = ClassDefinition::create(['name' => $name]);
                $this->applyClassOptions($definition, $options);
                break;
            case 'fieldcollection':
                $definition = new Fieldcollection\Definition();
                $definition->setKey($name);
                $this->applyContainerOptions($definition, $options);
                break;
            default: // objectbrick
                $definition = new Objectbrick\Definition();
                $definition->setKey($name);
                $this->applyContainerOptions($definition, $options);
                if (isset($options['classDefinitions']) && \is_array($options['classDefinitions'])) {
                    $definition->setClassDefinitions($this->normalizeClassDefinitions($options['classDefinitions']));
                }
                break;
        }

        $definition->setLayoutDefinitions($root);
        $definition->save();

        return $definition;
    }

    /**
     * Update the configuration of an existing field (not its name or type).
     *
     * @param array<string, mixed> $config
     *
     * @return ClassDefinition|Fieldcollection\Definition|Objectbrick\Definition
     *
     * @throws \InvalidArgumentException
     */
    public function updateField(string $scope, string $container, string $fieldName, array $config): object
    {
        $definition = $this->resolveDefinition($scope, $container);
        $root = $definition->getLayoutDefinitions();

        $node = $root instanceof Layout ? $this->findNode($root, $fieldName) : null;
        if (!$node instanceof Data) {
            throw new \InvalidArgumentException(\sprintf('Field "%s" was not found on %s "%s".', $fieldName, $scope, $container));
        }

        // Identity/type are immutable via update (use remove + add to change type).
        unset($config['name'], $config['datatype'], $config['fieldtype']);
        $node->setValues($config);

        $definition->setLayoutDefinitions($root);
        $definition->save();

        return $definition;
    }

    /**
     * Remove a field from a definition.
     *
     * @return ClassDefinition|Fieldcollection\Definition|Objectbrick\Definition
     *
     * @throws \InvalidArgumentException
     */
    public function removeField(string $scope, string $container, string $fieldName): object
    {
        $definition = $this->resolveDefinition($scope, $container);
        $root = $definition->getLayoutDefinitions();

        if (!$root instanceof Layout || !$this->removeFromTree($root, $fieldName)) {
            throw new \InvalidArgumentException(\sprintf('Field "%s" was not found on %s "%s".', $fieldName, $scope, $container));
        }

        $definition->setLayoutDefinitions($root);
        $definition->save();

        return $definition;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function exportClass(string $name): string
    {
        $class = ClassDefinition::getByName($name);
        if ($class === null) {
            throw new \InvalidArgumentException(\sprintf('DataObject class "%s" was not found.', $name));
        }

        return Service::generateClassDefinitionJson($class);
    }

    /**
     * Create or overwrite a class from an exported JSON definition.
     *
     * @throws \InvalidArgumentException
     */
    public function importClass(string $name, string $json, bool $overwrite): ClassDefinition
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid class name "%s".', $name));
        }
        if (json_decode($json, true) === null) {
            throw new \InvalidArgumentException('The "json" argument is not valid JSON.');
        }

        if (!$overwrite && $this->definitionExists('class', $name)) {
            throw new \InvalidArgumentException(\sprintf('Class "%s" already exists. Pass overwrite=true to replace its definition.', $name));
        }
        $class = ClassDefinition::getByName($name) ?? ClassDefinition::create(['name' => $name]);

        // importClassDefinitionFromJson builds the layout and saves internally.
        if (!Service::importClassDefinitionFromJson($class, $json, true)) {
            throw new \InvalidArgumentException('Failed to import class definition from JSON.');
        }

        return $class;
    }

    private function assertNotExists(string $scope, string $name): void
    {
        if ($this->definitionExists($scope, $name)) {
            throw new \InvalidArgumentException(\sprintf('A %s named "%s" already exists.', $scope, $name));
        }
    }

    /**
     * Existence check that consults both the model loader and the on-disk
     * definition file — the file check is immune to the name→id cache quirks
     * that can make getByName/getByKey miss right after other create/import ops
     * in the same process.
     */
    private function definitionExists(string $scope, string $name): bool
    {
        switch ($scope) {
            case 'class':
                if (ClassDefinition::getByName($name) !== null) {
                    return true;
                }

                return is_file((new ClassDefinition())->getDefinitionFile($name));
            case 'fieldcollection':
                if (Fieldcollection\Definition::getByKey($name) !== null) {
                    return true;
                }
                $fc = new Fieldcollection\Definition();
                $fc->setKey($name);

                return is_file($fc->getDefinitionFile());
            case 'objectbrick':
                if (Objectbrick\Definition::getByKey($name) !== null) {
                    return true;
                }
                $ob = new Objectbrick\Definition();
                $ob->setKey($name);

                return is_file($ob->getDefinitionFile());
            default:
                return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $fieldSpecs
     */
    private function buildRootLayout(array $fieldSpecs): Layout
    {
        $children = [];
        foreach ($fieldSpecs as $spec) {
            $fieldName = (string) ($spec['name'] ?? '');
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $fieldName) !== 1) {
                throw new \InvalidArgumentException(\sprintf('Invalid field name "%s".', $fieldName));
            }
            if (($spec['fieldtype'] ?? '') === '') {
                throw new \InvalidArgumentException(\sprintf('Field "%s" is missing a fieldtype.', $fieldName));
            }
            $children[] = $this->buildNode($spec);
        }

        $root = Service::generateLayoutTreeFromArray([
            'datatype' => 'layout',
            'fieldtype' => 'panel',
            'name' => 'pimcore_root',
            'title' => 'Layout',
            'children' => $children,
        ], false);

        if (!$root instanceof Layout) {
            throw new \InvalidArgumentException('Failed to build the layout tree (check field types / config).');
        }

        return $root;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyClassOptions(ClassDefinition $class, array $options): void
    {
        if (isset($options['description'])) {
            $class->setDescription((string) $options['description']);
        }
        if (isset($options['group'])) {
            $class->setGroup((string) $options['group']);
        }
        if (isset($options['parentClass'])) {
            $class->setParentClass((string) $options['parentClass']);
        }
        if (\array_key_exists('allowInherit', $options)) {
            $class->setAllowInherit((bool) $options['allowInherit']);
        }
        if (\array_key_exists('allowVariants', $options)) {
            $class->setAllowVariants((bool) $options['allowVariants']);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyContainerOptions(object $definition, array $options): void
    {
        if (isset($options['title']) && method_exists($definition, 'setTitle')) {
            $definition->setTitle((string) $options['title']);
        }
        if (isset($options['group']) && method_exists($definition, 'setGroup')) {
            $definition->setGroup((string) $options['group']);
        }
    }

    /**
     * @param array<int, mixed> $list
     *
     * @return list<array{classname: string, fieldname: string}>
     */
    private function normalizeClassDefinitions(array $list): array
    {
        $out = [];
        foreach ($list as $entry) {
            if (\is_array($entry) && isset($entry['class'], $entry['field'])) {
                $out[] = ['classname' => (string) $entry['class'], 'fieldname' => (string) $entry['field']];
            }
        }

        return $out;
    }

    private function findNode(Data|Layout $node, string $name): Data|Layout|null
    {
        if ($node->getName() === $name) {
            return $node;
        }
        if (method_exists($node, 'getChildren')) {
            foreach ($node->getChildren() as $child) {
                if (($child instanceof Data || $child instanceof Layout)
                    && ($found = $this->findNode($child, $name)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function removeFromTree(Data|Layout $node, string $name): bool
    {
        if (!method_exists($node, 'getChildren') || !method_exists($node, 'setChildren')) {
            return false;
        }

        $removed = false;
        $kept = [];
        foreach ($node->getChildren() as $child) {
            if (($child instanceof Data || $child instanceof Layout) && $child->getName() === $name) {
                $removed = true;
                continue;
            }
            if (($child instanceof Data || $child instanceof Layout) && $this->removeFromTree($child, $name)) {
                $removed = true;
            }
            $kept[] = $child;
        }
        $node->setChildren($kept);

        return $removed;
    }

    /**
     * @return ClassDefinition|Fieldcollection\Definition|Objectbrick\Definition
     */
    private function resolveDefinition(string $scope, string $container): object
    {
        $definition = match ($scope) {
            'class' => ClassDefinition::getByName($container),
            'fieldcollection' => Fieldcollection\Definition::getByKey($container),
            'objectbrick' => Objectbrick\Definition::getByKey($container),
            default => throw new \InvalidArgumentException(
                \sprintf('Invalid scope "%s". Allowed: %s.', $scope, implode(', ', self::SCOPES)),
            ),
        };

        if ($definition === null) {
            throw new \InvalidArgumentException(\sprintf('No %s named "%s" was found.', $scope, $container));
        }

        return $definition;
    }

    /**
     * @param array{name: string, fieldtype: string, title?: ?string, mandatory?: bool, config?: array<string, mixed>} $spec
     *
     * @return array<string, mixed>
     */
    private function buildNode(array $spec): array
    {
        $node = $spec['config'] ?? [];
        // Core identity keys always win over anything supplied in config.
        $node['name'] = $spec['name'];
        $node['datatype'] = 'data';
        $node['fieldtype'] = $spec['fieldtype'];
        if (($spec['title'] ?? null) !== null && $spec['title'] !== '') {
            $node['title'] = $spec['title'];
        }
        if (!empty($spec['mandatory'])) {
            $node['mandatory'] = true;
        }

        return $node;
    }

    /**
     * Depth-first search for a layout element or container data field (anything
     * that accepts children) with the given name.
     */
    private function findContainer(Data|Layout $node, string $name): Data|Layout|null
    {
        if ($node->getName() === $name && method_exists($node, 'addChild')) {
            return $node;
        }

        if (method_exists($node, 'getChildren')) {
            foreach ($node->getChildren() as $child) {
                if (($child instanceof Data || $child instanceof Layout)
                    && ($found = $this->findContainer($child, $name)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function newRootLayout(): Layout
    {
        $root = new Layout();
        $root->setName('pimcore_root');
        $root->setChildren([]);

        return $root;
    }
}
