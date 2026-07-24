<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Repository;

use Pimcore\Model\Document\DocType;

/**
 * CRUD access layer over Pimcore's predefined document types
 * ({@see DocType}, stored via the location-aware config / settings store).
 */
final class DocumentTypeRepository
{
    /**
     * Common document type values. Not enforced (Pimcore allows custom types),
     * but surfaced to guide callers.
     */
    public const COMMON_TYPES = ['page', 'snippet', 'email', 'link', 'hardlink', 'folder'];

    /**
     * @return DocType[]
     */
    public function all(): array
    {
        return (new DocType\Listing())->load();
    }

    public function find(string $id): ?DocType
    {
        return DocType::getById($id);
    }

    public function create(string $name, string $type, array $optional): DocType
    {
        $docType = new DocType();
        $docType->setName($name);
        $docType->setType($type);
        $this->apply($docType, $optional);
        $docType->save();

        return $docType;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(string $id, array $changes): ?DocType
    {
        $docType = DocType::getById($id);
        if ($docType === null) {
            return null;
        }

        $this->apply($docType, $changes);
        $docType->save();

        return $docType;
    }

    public function delete(string $id): bool
    {
        $docType = DocType::getById($id);
        if ($docType === null) {
            return false;
        }

        $docType->delete();

        return true;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function apply(DocType $docType, array $values): void
    {
        if (\array_key_exists('name', $values)) {
            $docType->setName((string) $values['name']);
        }
        if (\array_key_exists('type', $values)) {
            $docType->setType((string) $values['type']);
        }
        if (\array_key_exists('group', $values)) {
            $docType->setGroup($values['group'] !== null ? (string) $values['group'] : null);
        }
        if (\array_key_exists('controller', $values)) {
            $docType->setController($values['controller'] !== null ? (string) $values['controller'] : null);
        }
        if (\array_key_exists('template', $values)) {
            $docType->setTemplate($values['template'] !== null ? (string) $values['template'] : null);
        }
        if (\array_key_exists('priority', $values)) {
            $docType->setPriority((int) $values['priority']);
        }
        if (\array_key_exists('staticGeneratorEnabled', $values)) {
            $docType->setStaticGeneratorEnabled((bool) $values['staticGeneratorEnabled']);
        }
    }
}
