<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Repository\DocumentTypeRepository;
use Aashan\PimcoreMcpBundle\Repository\PredefinedPropertyRepository;
use Mcp\Capability\Attribute\McpTool;
use Pimcore\Model\Document\DocType;
use Pimcore\Model\Property\Predefined;

/**
 * MCP tools for managing Pimcore predefined admin configuration:
 * document types and predefined properties.
 */
final class PredefinedConfigTool
{
    public function __construct(
        private readonly DocumentTypeRepository $documentTypes,
        private readonly PredefinedPropertyRepository $properties,
    ) {}

    // ---------------------------------------------------------------------
    // Document types
    // ---------------------------------------------------------------------

    /**
     * List all predefined document types.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_document_types', description: 'List all Pimcore predefined document types.')]
    public function listDocumentTypes(): array
    {
        $items = array_map(fn (DocType $d): array => $this->serializeDocType($d), $this->documentTypes->all());

        return ['count' => \count($items), 'documentTypes' => $items, 'commonTypes' => DocumentTypeRepository::COMMON_TYPES];
    }

    /**
     * Create a predefined document type.
     *
     * @param string      $name       Display name.
     * @param string      $type       Document type, e.g. "page", "snippet", "email", "link".
     * @param string|null $controller Controller service/reference (e.g. "App\\Controller\\DefaultController::defaultAction").
     * @param string|null $template   Template path.
     * @param string|null $group      Optional group used in the admin dropdown.
     * @param int         $priority   Sort priority.
     * @param bool        $staticGeneratorEnabled Enable static page generation.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_document_type', description: 'Create a Pimcore predefined document type (preset controller/template for a document kind).')]
    public function createDocumentType(
        string $name,
        string $type,
        ?string $controller = null,
        ?string $template = null,
        ?string $group = null,
        int $priority = 0,
        bool $staticGeneratorEnabled = false,
    ): array {
        if ($type === '') {
            return ['error' => 'The "type" argument is required (e.g. page, snippet, email, link).'];
        }

        $docType = $this->documentTypes->create($name, $type, [
            'controller' => $controller,
            'template' => $template,
            'group' => $group,
            'priority' => $priority,
            'staticGeneratorEnabled' => $staticGeneratorEnabled,
        ]);

        return ['created' => true, 'documentType' => $this->serializeDocType($docType)];
    }

    /**
     * Update a predefined document type. Only the provided fields change.
     *
     * @param string      $id         The document type id.
     * @param string|null $name       New display name.
     * @param string|null $type       New document type.
     * @param string|null $controller New controller.
     * @param string|null $template   New template.
     * @param string|null $group      New group.
     * @param int|null    $priority   New priority.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'update_document_type', description: 'Update a Pimcore predefined document type by id. Only provided fields change.')]
    public function updateDocumentType(
        string $id,
        ?string $name = null,
        ?string $type = null,
        ?string $controller = null,
        ?string $template = null,
        ?string $group = null,
        ?int $priority = null,
    ): array {
        $changes = $this->collect([
            'name' => $name,
            'type' => $type,
            'controller' => $controller,
            'template' => $template,
            'group' => $group,
            'priority' => $priority,
        ]);
        if ($changes === []) {
            return ['error' => 'No fields to update were provided.'];
        }

        $docType = $this->documentTypes->update($id, $changes);
        if ($docType === null) {
            return ['error' => \sprintf('Document type "%s" was not found.', $id)];
        }

        return ['updated' => true, 'documentType' => $this->serializeDocType($docType)];
    }

    /**
     * Delete a predefined document type by id.
     *
     * @param string $id The document type id.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'delete_document_type', description: 'Delete a Pimcore predefined document type by id.')]
    public function deleteDocumentType(string $id): array
    {
        if (!$this->documentTypes->delete($id)) {
            return ['error' => \sprintf('Document type "%s" was not found.', $id)];
        }

        return ['deleted' => true, 'id' => $id];
    }

    // ---------------------------------------------------------------------
    // Predefined properties
    // ---------------------------------------------------------------------

    /**
     * List all predefined properties.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_predefined_properties', description: 'List all Pimcore predefined properties.')]
    public function listPredefinedProperties(): array
    {
        $items = array_map(fn (Predefined $p): array => $this->serializeProperty($p), $this->properties->all());

        return [
            'count' => \count($items),
            'predefinedProperties' => $items,
            'allowedTypes' => PredefinedPropertyRepository::TYPES,
            'allowedCtypes' => PredefinedPropertyRepository::CTYPES,
        ];
    }

    /**
     * Create a predefined property.
     *
     * @param string      $name        Display name.
     * @param string      $key         Property key (the identifier used on elements).
     * @param string      $type        Value type: text, document, asset, object, bool, select.
     * @param string      $ctype       Element type it applies to: document, asset, object.
     * @param string|null $data        Default value.
     * @param string|null $config      Extra config (e.g. select options as a comma-separated list).
     * @param string|null $description Description.
     * @param bool        $inheritable Whether the property is inheritable down the tree.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_predefined_property', description: 'Create a Pimcore predefined property. type is one of text/document/asset/object/bool/select; ctype is document/asset/object.')]
    public function createPredefinedProperty(
        string $name,
        string $key,
        string $type,
        string $ctype,
        ?string $data = null,
        ?string $config = null,
        ?string $description = null,
        bool $inheritable = false,
    ): array {
        try {
            $property = $this->properties->create($name, $key, $type, $ctype, [
                'data' => $data,
                'config' => $config,
                'description' => $description,
                'inheritable' => $inheritable,
            ]);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        return ['created' => true, 'predefinedProperty' => $this->serializeProperty($property)];
    }

    /**
     * Update a predefined property. Only the provided fields change.
     *
     * @param string      $id          The property id.
     * @param string|null $name        New display name.
     * @param string|null $key         New key.
     * @param string|null $type        New value type.
     * @param string|null $ctype       New element type.
     * @param string|null $data        New default value.
     * @param string|null $config      New extra config.
     * @param string|null $description New description.
     * @param bool|null   $inheritable New inheritable flag.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'update_predefined_property', description: 'Update a Pimcore predefined property by id. Only provided fields change.')]
    public function updatePredefinedProperty(
        string $id,
        ?string $name = null,
        ?string $key = null,
        ?string $type = null,
        ?string $ctype = null,
        ?string $data = null,
        ?string $config = null,
        ?string $description = null,
        ?bool $inheritable = null,
    ): array {
        $changes = $this->collect([
            'name' => $name,
            'key' => $key,
            'type' => $type,
            'ctype' => $ctype,
            'data' => $data,
            'config' => $config,
            'description' => $description,
            'inheritable' => $inheritable,
        ]);
        if ($changes === []) {
            return ['error' => 'No fields to update were provided.'];
        }

        try {
            $property = $this->properties->update($id, $changes);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        if ($property === null) {
            return ['error' => \sprintf('Predefined property "%s" was not found.', $id)];
        }

        return ['updated' => true, 'predefinedProperty' => $this->serializeProperty($property)];
    }

    /**
     * Delete a predefined property by id.
     *
     * @param string $id The property id.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'delete_predefined_property', description: 'Delete a Pimcore predefined property by id.')]
    public function deletePredefinedProperty(string $id): array
    {
        if (!$this->properties->delete($id)) {
            return ['error' => \sprintf('Predefined property "%s" was not found.', $id)];
        }

        return ['deleted' => true, 'id' => $id];
    }

    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function collect(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDocType(DocType $docType): array
    {
        return [
            'id' => $docType->getId(),
            'name' => $docType->getName(),
            'type' => $docType->getType(),
            'group' => $docType->getGroup(),
            'controller' => $docType->getController(),
            'template' => $docType->getTemplate(),
            'priority' => $docType->getPriority(),
            'staticGeneratorEnabled' => $docType->getStaticGeneratorEnabled(),
            'modificationDate' => $docType->getModificationDate(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProperty(Predefined $property): array
    {
        return [
            'id' => $property->getId(),
            'name' => $property->getName(),
            'key' => $property->getKey(),
            'type' => $property->getType(),
            'ctype' => $property->getCtype(),
            'data' => $property->getData(),
            'config' => $property->getConfig(),
            'description' => $property->getDescription(),
            'inheritable' => $property->getInheritable(),
            'modificationDate' => $property->getModificationDate(),
        ];
    }
}
