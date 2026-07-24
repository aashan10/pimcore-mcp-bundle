<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Serializer;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\Document;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\Service;
use Pimcore\Model\Property;
use Pimcore\Tool;

/**
 * Serialises Pimcore content elements (documents, assets, data objects) into
 * compact, JSON-friendly arrays for inspection.
 *
 * Values are bounded: nested structures stop at {@see self::MAX_DEPTH}, lists
 * are capped at {@see self::MAX_ITEMS}, long strings are truncated, and every
 * referenced element is reduced to a `{type, id, path}` reference rather than
 * being expanded inline.
 */
final class ElementSerializer
{
    private const MAX_DEPTH = 4;
    private const MAX_ITEMS = 100;
    private const MAX_STRING = 500;

    /**
     * Lightweight summary used for tree listings.
     *
     * @return array<string, mixed>
     */
    public function summary(ElementInterface $element): array
    {
        $summary = [
            'elementType' => Service::getElementType($element),
            'id' => $element->getId(),
            'type' => $element->getType(),
            'key' => $element->getKey(),
            'path' => $element->getRealFullPath(),
        ];

        if ($element instanceof Document || $element instanceof DataObject\AbstractObject) {
            $summary['published'] = $this->isPublished($element);
        }
        if (method_exists($element, 'hasChildren')) {
            $summary['hasChildren'] = $element->hasChildren();
        }

        return $summary;
    }

    /**
     * Full, type-specific detail for a single element.
     *
     * @return array<string, mixed>
     */
    public function detail(ElementInterface $element): array
    {
        $detail = $this->summary($element);
        $detail['modificationDate'] = $element->getModificationDate();

        $detail += match (true) {
            $element instanceof DataObject\Concrete => $this->dataObjectDetail($element),
            $element instanceof Document => $this->documentDetail($element),
            $element instanceof Asset => $this->assetDetail($element),
            default => [],
        };

        $properties = $this->properties($element);
        if ($properties !== []) {
            $detail['properties'] = $properties;
        }

        return $detail;
    }

    /**
     * Serialise only the named fields of a data object (for query result previews).
     *
     * @param string[] $fieldNames
     *
     * @return array<string, mixed>
     */
    public function fieldPreview(DataObject\Concrete $object, array $fieldNames): array
    {
        $definitions = $object->getClass()->getFieldDefinitions();
        $preview = [];
        foreach ($fieldNames as $name) {
            if (isset($definitions[$name])) {
                $preview[$name] = $this->fieldValue($definitions[$name], $object, 0);
            }
        }

        return $preview;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataObjectDetail(DataObject\Concrete $object): array
    {
        $values = [];
        foreach ($object->getClass()->getFieldDefinitions() as $name => $fieldDefinition) {
            $values[$name] = $this->fieldValue($fieldDefinition, $object, 0);
        }

        return [
            'className' => $object->getClassName(),
            'fields' => $values,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentDetail(Document $document): array
    {
        $detail = [];

        if ($document instanceof Document\PageSnippet) {
            $detail['controller'] = $document->getController();
            $detail['template'] = $document->getTemplate();

            if ($document instanceof Document\Page) {
                $detail['title'] = $document->getTitle();
                $detail['metaDescription'] = $document->getDescription();
            }

            $editables = [];
            foreach ($document->getEditables() as $editable) {
                $editables[] = [
                    'name' => $editable->getName(),
                    'type' => $editable->getType(),
                    'data' => $this->value($editable->getData(), 1),
                ];
                if (\count($editables) >= self::MAX_ITEMS) {
                    break;
                }
            }
            $detail['editables'] = $editables;
        }

        if ($document instanceof Document\Link && method_exists($document, 'getHref')) {
            $detail['href'] = $document->getHref();
        }

        return $detail;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetDetail(Asset $asset): array
    {
        $detail = [
            'filename' => $asset->getFilename(),
            'mimeType' => $asset->getMimeType(),
            'fileSize' => $asset->getFileSize(true),
        ];

        if ($asset instanceof Asset\Image) {
            $detail['width'] = $asset->getWidth();
            $detail['height'] = $asset->getHeight();
        }

        $metadata = [];
        foreach ($asset->getMetadata() as $entry) {
            if (!\is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            $metadata[] = [
                'name' => $entry['name'],
                'type' => $entry['type'] ?? null,
                'language' => ($entry['language'] ?? '') !== '' ? $entry['language'] : null,
                'data' => $this->value($entry['data'] ?? null, 2),
            ];
            if (\count($metadata) >= self::MAX_ITEMS) {
                break;
            }
        }
        if ($metadata !== []) {
            $detail['metadata'] = $metadata;
        }

        return $detail;
    }

    /**
     * Serialise one field of a data object / brick / collection item carrier.
     */
    private function fieldValue(Data $fieldDefinition, object $carrier, int $depth): mixed
    {
        $name = (string) $fieldDefinition->getName();
        $getter = 'get' . ucfirst($name);

        if ($depth > self::MAX_DEPTH) {
            return '[max depth reached]';
        }

        return match ($fieldDefinition->getFieldType()) {
            'localizedfields' => $this->localizedValues($fieldDefinition, $carrier, $depth),
            'objectbricks' => $this->objectBrickValues($carrier, $getter, $depth),
            'fieldcollections' => $this->fieldCollectionValues($carrier, $getter, $depth),
            default => method_exists($carrier, $getter) ? $this->value($carrier->{$getter}(), $depth) : null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function localizedValues(Data $fieldDefinition, object $carrier, int $depth): array
    {
        $result = [];
        $children = method_exists($fieldDefinition, 'getFieldDefinitions') ? $fieldDefinition->getFieldDefinitions() : [];

        foreach (Tool::getValidLanguages() as $language) {
            $perLanguage = [];
            foreach ($children as $child) {
                $getter = 'get' . ucfirst((string) $child->getName());
                if (method_exists($carrier, $getter)) {
                    $perLanguage[$child->getName()] = $this->value($carrier->{$getter}($language), $depth + 1);
                }
            }
            if ($perLanguage !== []) {
                $result[$language] = $perLanguage;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function objectBrickValues(object $carrier, string $getter, int $depth): array
    {
        if (!method_exists($carrier, $getter)) {
            return [];
        }

        $container = $carrier->{$getter}();
        if (!$container instanceof DataObject\Objectbrick) {
            return [];
        }

        $result = [];
        foreach ($container->getItems() as $item) {
            $definition = DataObject\Objectbrick\Definition::getByKey($item->getType());
            if ($definition === null) {
                continue;
            }
            $brick = [];
            foreach ($definition->getFieldDefinitions() as $fieldDefinition) {
                $brick[$fieldDefinition->getName()] = $this->fieldValue($fieldDefinition, $item, $depth + 1);
            }
            $result[$item->getType()] = $brick;
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fieldCollectionValues(object $carrier, string $getter, int $depth): array
    {
        if (!method_exists($carrier, $getter)) {
            return [];
        }

        $container = $carrier->{$getter}();
        if (!$container instanceof DataObject\Fieldcollection) {
            return [];
        }

        $result = [];
        foreach ($container->getItems() as $index => $item) {
            $definition = DataObject\Fieldcollection\Definition::getByKey($item->getType());
            if ($definition === null) {
                continue;
            }
            $fields = [];
            foreach ($definition->getFieldDefinitions() as $fieldDefinition) {
                $fields[$fieldDefinition->getName()] = $this->fieldValue($fieldDefinition, $item, $depth + 1);
            }
            $result[] = ['type' => $item->getType(), 'index' => $index, 'fields' => $fields];
            if (\count($result) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $result;
    }

    private function value(mixed $value, int $depth): mixed
    {
        if ($value === null || \is_bool($value) || \is_int($value) || \is_float($value)) {
            return $value;
        }

        if (\is_string($value)) {
            return \strlen($value) > self::MAX_STRING
                ? substr($value, 0, self::MAX_STRING) . '… [truncated]'
                : $value;
        }

        if ($value instanceof ElementInterface) {
            return $this->reference($value);
        }

        // Relation metadata wrappers (ObjectMetadata / ElementMetadata) wrap an element.
        if (\is_object($value) && method_exists($value, 'getElement')) {
            return $this->reference($value->getElement());
        }

        if (\is_array($value)) {
            if ($depth > self::MAX_DEPTH) {
                return '[' . \count($value) . ' items]';
            }
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->value($item, $depth + 1);
                if (\count($result) >= self::MAX_ITEMS) {
                    $result[] = '… [truncated]';
                    break;
                }
            }

            return $result;
        }

        if (\is_object($value)) {
            if (method_exists($value, '__toString')) {
                return $this->value((string) $value, $depth);
            }

            return ['_object' => (new \ReflectionClass($value))->getShortName()];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reference(?ElementInterface $element): ?array
    {
        if ($element === null) {
            return null;
        }

        return [
            'type' => Service::getElementType($element),
            'id' => $element->getId(),
            'path' => $element->getRealFullPath(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(ElementInterface $element): array
    {
        $properties = [];
        foreach ($element->getProperties() as $property) {
            if (!$property instanceof Property) {
                continue;
            }
            $properties[$property->getName()] = [
                'type' => $property->getType(),
                'value' => $this->value($property->getData(), 2),
                'inherited' => method_exists($property, 'getInherited') ? $property->getInherited() : null,
            ];
        }

        return $properties;
    }

    private function isPublished(ElementInterface $element): bool
    {
        return method_exists($element, 'getPublished') ? (bool) $element->getPublished() : true;
    }
}
