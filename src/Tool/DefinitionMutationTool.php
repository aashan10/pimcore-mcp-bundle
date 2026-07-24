<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Extractor\DefinitionExtractor;
use Aashan\PimcoreMcpBundle\Mutation\DefinitionFieldMutator;
use Mcp\Capability\Attribute\McpTool;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Objectbrick;

/**
 * MCP tools for authoring Pimcore definitions: create classes / field
 * collections / object bricks, add/update/remove fields, and import/export a
 * class as JSON.
 */
final class DefinitionMutationTool
{
    private const REBUILD_NOTE = 'Definition saved and PHP classes regenerated. Run "run_maintenance" with '
        . 'action "rebuild_classes" if tables look out of sync; new typed getters/setters appear on the next server start.';

    public function __construct(
        private readonly DefinitionFieldMutator $mutator,
        private readonly DefinitionExtractor $extractor,
    ) {}

    /**
     * Add a new field to a DataObject class, field collection or object brick
     * definition, then persist it (regenerates the PHP model classes and updates
     * the database tables).
     *
     * @param string      $scope     One of: class, fieldcollection, objectbrick.
     * @param string      $container The class name (scope=class) or the collection/brick key.
     * @param string      $name      The new field's machine name (letters, digits, underscores).
     * @param string      $fieldtype Pimcore field type, e.g. "input", "textarea", "numeric",
     *                               "checkbox", "select", "manyToOneRelation".
     * @param string|null $title     Editor label (defaults to the name).
     * @param bool        $mandatory Whether the field is required.
     * @param string|null $config    JSON object of extra field-type-specific settings merged into the
     *                               definition, e.g. {"maxLength":50} for input, {"options":[{"key":"A","value":"a"}]}
     *                               for select, {"classes":[{"classes":"Manufacturer"}]} for a relation.
     * @param string|null $parent    Name of an existing layout element or container field (e.g.
     *                               "localizedfields") to nest the field under. Defaults to the root.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'add_field',
        description: 'Add a field to a Pimcore DataObject class, field collection or object brick definition and persist it. This regenerates PHP model classes and alters DB tables. Use the get_* schema tools first to see existing fields and layout containers.',
    )]
    public function addField(
        string $scope,
        string $container,
        string $name,
        string $fieldtype,
        ?string $title = null,
        bool $mandatory = false,
        ?string $config = null,
        ?string $parent = null,
    ): array {
        $decodedConfig = [];
        if ($config !== null && $config !== '') {
            $decoded = json_decode($config, true);
            if (!\is_array($decoded)) {
                return ['error' => 'The "config" argument must be a JSON object.'];
            }
            $decodedConfig = $decoded;
        }

        try {
            $definition = $this->mutator->addField($scope, $container, [
                'name' => $name,
                'fieldtype' => $fieldtype,
                'title' => $title,
                'mandatory' => $mandatory,
                'config' => $decodedConfig,
                'parent' => $parent,
            ]);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => \sprintf('Failed to add field: %s', $e->getMessage())];
        }

        return [
            'added' => true,
            'field' => $name,
            'schema' => $this->serialize($definition),
            '_note' => 'Definition saved and PHP classes regenerated. The typed getter/setter from the '
                . '*_methods tools becomes visible on the next server start (the class is already loaded in this process).',
        ];
    }

    /**
     * Create a new DataObject class, field collection or object brick, optionally
     * with an initial set of fields. Persists and regenerates PHP classes / DB tables.
     *
     * @param string      $scope   One of: class, fieldcollection, objectbrick.
     * @param string      $name    Class name / collection or brick key (PascalCase recommended).
     * @param string|null $fields  JSON array of field specs, e.g.
     *                             [{"name":"headline","fieldtype":"input","title":"Headline"},
     *                              {"name":"price","fieldtype":"numeric","config":{"integer":false}}].
     * @param string|null $options JSON object of definition options. class: description, group, parentClass,
     *                             allowInherit, allowVariants. field collection / object brick: title, group;
     *                             object brick also: classDefinitions [{"class":"Car","field":"attributes"}].
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_definition',
        description: 'Create a Pimcore DataObject class, field collection or object brick (optionally with fields), then persist it. Regenerates PHP classes and DB tables.',
    )]
    public function createDefinition(string $scope, string $name, ?string $fields = null, ?string $options = null): array
    {
        $fieldSpecs = $this->decodeJson($fields, 'fields', true);
        if ($fieldSpecs === false) {
            return ['error' => 'The "fields" argument must be a JSON array of field specs.'];
        }
        $optionValues = $this->decodeJson($options, 'options', false);
        if ($optionValues === false) {
            return ['error' => 'The "options" argument must be a JSON object.'];
        }

        try {
            $definition = $this->mutator->createDefinition($scope, $name, $fieldSpecs, $optionValues);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => \sprintf('Failed to create definition: %s', $e->getMessage())];
        }

        return ['created' => true, 'scope' => $scope, 'name' => $name, 'schema' => $this->serialize($definition), '_note' => self::REBUILD_NOTE];
    }

    /**
     * Update the configuration of an existing field (its validators, defaults,
     * options, title, mandatory, …). Cannot change the field name or type.
     *
     * @param string $scope     class, fieldcollection or objectbrick.
     * @param string $container Class name or collection/brick key.
     * @param string $field     The field's machine name.
     * @param string $config    JSON object of settings to apply, e.g. {"mandatory":true,"title":"New title","maxLength":80}.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_field',
        description: 'Update an existing field\'s configuration (title, mandatory, validators, options, …) on a class/field collection/object brick. Cannot change name or fieldtype.',
    )]
    public function updateField(string $scope, string $container, string $field, string $config): array
    {
        $configValues = $this->decodeJson($config, 'config', false);
        if ($configValues === false || $configValues === []) {
            return ['error' => 'The "config" argument must be a non-empty JSON object of settings.'];
        }

        try {
            $definition = $this->mutator->updateField($scope, $container, $field, $configValues);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => \sprintf('Failed to update field: %s', $e->getMessage())];
        }

        return ['updated' => true, 'field' => $field, 'schema' => $this->serialize($definition), '_note' => self::REBUILD_NOTE];
    }

    /**
     * Remove a field from a class / field collection / object brick. This drops
     * its database column on save.
     *
     * @param string $scope     class, fieldcollection or objectbrick.
     * @param string $container Class name or collection/brick key.
     * @param string $field     The field's machine name.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'remove_field',
        description: 'Remove a field from a Pimcore class/field collection/object brick. Drops the DB column and regenerates classes.',
    )]
    public function removeField(string $scope, string $container, string $field): array
    {
        try {
            $definition = $this->mutator->removeField($scope, $container, $field);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => \sprintf('Failed to remove field: %s', $e->getMessage())];
        }

        return ['removed' => true, 'field' => $field, 'schema' => $this->serialize($definition), '_note' => self::REBUILD_NOTE];
    }

    /**
     * Export a DataObject class definition as JSON (Pimcore's native format),
     * suitable for cloning via import_class.
     *
     * @param string $className The class name.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'export_class', description: 'Export a Pimcore DataObject class definition as JSON (native format, for cloning or version control).')]
    public function exportClass(string $className): array
    {
        try {
            $json = $this->mutator->exportClass($className);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        return ['className' => $className, 'json' => $json];
    }

    /**
     * Create or overwrite a DataObject class from an exported JSON definition.
     *
     * @param string $className The target class name.
     * @param string $json      The class definition JSON (from export_class).
     * @param bool   $overwrite Replace the definition if the class already exists.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'import_class', description: 'Create or overwrite a Pimcore DataObject class from an exported JSON definition. Regenerates PHP classes and DB tables.')]
    public function importClass(string $className, string $json, bool $overwrite = false): array
    {
        try {
            $class = $this->mutator->importClass($className, $json, $overwrite);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => \sprintf('Failed to import class: %s', $e->getMessage())];
        }

        return ['imported' => true, 'className' => $className, 'schema' => $this->serialize($class), '_note' => self::REBUILD_NOTE];
    }

    /**
     * @return array<mixed>|false Decoded value, [] when null/empty, or false on invalid JSON/shape.
     */
    private function decodeJson(?string $json, string $arg, bool $expectList): array|false
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return false;
        }
        if ($expectList && !array_is_list($decoded)) {
            return false;
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(object $definition): array
    {
        return match (true) {
            $definition instanceof ClassDefinition => $this->extractor->extractClass($definition)->toArray(),
            $definition instanceof Objectbrick\Definition => $this->extractor->extractObjectBrick($definition)->toArray(),
            $definition instanceof Fieldcollection\Definition => $this->extractor->extractFieldCollection($definition)->toArray(),
            default => [],
        };
    }
}
