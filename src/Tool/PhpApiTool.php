<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Extractor\ClassApiReflector;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP tools that expose the public PHP API (method signatures) of the generated
 * Pimcore model classes, so agents can call getters/setters with correct types.
 */
final class PhpApiTool
{
    private const BASE_API_HINT = 'Only schema-specific methods are listed. Standard Pimcore model methods '
        . '(save, delete, getById, getList, getParent, getProperties, publish, …) are inherited from the '
        . 'class shown in "extends". Each accessor\'s field maps to a definition returned by the get_* schema tools.';

    public function __construct(
        private readonly ClassApiReflector $reflector,
    ) {}

    /**
     * List the public method signatures (field getters/setters and other
     * class-specific methods, with PHP types) of a generated DataObject class.
     *
     * @param string $className The class name, e.g. "Car".
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_data_object_methods',
        description: 'Get the public PHP method signatures (typed getters/setters and other class-specific methods) of a generated Pimcore DataObject class, to write correct code against it.',
    )]
    public function getDataObjectMethods(string $className): array
    {
        $api = $this->reflector->reflectClass($className);
        if ($api === null) {
            return $this->notFound('DataObject class', $className, 'list_data_object_classes');
        }

        return ['api' => $api->toArray(), '_note' => self::BASE_API_HINT];
    }

    /**
     * List the public method signatures of a generated object brick data class.
     *
     * @param string $key The object brick key, e.g. "Engine".
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_object_brick_methods',
        description: 'Get the public PHP method signatures (typed getters/setters) of a generated Pimcore object brick data class.',
    )]
    public function getObjectBrickMethods(string $key): array
    {
        $api = $this->reflector->reflectObjectBrick($key);
        if ($api === null) {
            return $this->notFound('object brick', $key, 'list_object_bricks');
        }

        return ['api' => $api->toArray(), '_note' => self::BASE_API_HINT];
    }

    /**
     * List the public method signatures of a generated field collection data class.
     *
     * @param string $key The field collection key, e.g. "Dimensions".
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_field_collection_methods',
        description: 'Get the public PHP method signatures (typed getters/setters) of a generated Pimcore field collection data class.',
    )]
    public function getFieldCollectionMethods(string $key): array
    {
        $api = $this->reflector->reflectFieldCollection($key);
        if ($api === null) {
            return $this->notFound('field collection', $key, 'list_field_collections');
        }

        return ['api' => $api->toArray(), '_note' => self::BASE_API_HINT];
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(string $type, string $name, string $listTool): array
    {
        return [
            'error' => \sprintf('No generated PHP class was found for %s "%s".', $type, $name),
            '_hint' => \sprintf(
                'Call "%s" to see the available names. The PHP class may be missing if class definitions have not been rebuilt (bin/console pimcore:deployment:classes-rebuild).',
                $listTool,
            ),
        ];
    }
}
