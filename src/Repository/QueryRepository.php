<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Repository;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\Document;

/**
 * Safe querying of Pimcore content: data objects (by class + field conditions)
 * and documents/assets (by path/type/name).
 *
 * Object field names are validated against the class definition and operators
 * against a fixed allowlist, so conditions are built only from known
 * identifiers with bound parameters — no arbitrary SQL.
 */
final class QueryRepository
{
    /** operator => whether it consumes a value / is multi-valued */
    private const OPERATORS = [
        '=' => 'single', '!=' => 'single',
        '>' => 'single', '>=' => 'single', '<' => 'single', '<=' => 'single',
        'LIKE' => 'single', 'NOT LIKE' => 'single',
        'IN' => 'multi', 'NOT IN' => 'multi',
        'IS NULL' => 'none', 'IS NOT NULL' => 'none',
    ];

    private const SYSTEM_FIELDS = ['id', 'key', 'published', 'parentId', 'creationDate', 'modificationDate', 'index', 'type', 'classId', 'className', 'path'];

    private const ORDER_DIRECTIONS = ['ASC', 'DESC'];

    /**
     * @param list<array{field: string, operator: string, value?: mixed}> $filters
     *
     * @return array{items: DataObject\Concrete[], total: int}
     *
     * @throws \InvalidArgumentException
     */
    public function findObjects(
        string $className,
        array $filters,
        ?string $pathPrefix,
        bool $includeUnpublished,
        ?string $orderBy,
        string $orderDir,
        int $limit,
        int $offset,
    ): array {
        $class = DataObject\ClassDefinition::getByName($className);
        if ($class === null) {
            throw new \InvalidArgumentException(\sprintf('DataObject class "%s" was not found.', $className));
        }

        $listingClass = 'Pimcore\\Model\\DataObject\\' . $className . '\\Listing';
        if (!class_exists($listingClass)) {
            throw new \InvalidArgumentException(\sprintf('No generated listing for class "%s" (rebuild classes?).', $className));
        }

        $allowedFields = array_merge(array_keys($class->getFieldDefinitions()), self::SYSTEM_FIELDS);

        $conditions = [];
        $variables = [];
        foreach ($filters as $filter) {
            [$sql, $vars] = $this->buildCondition($filter, $allowedFields);
            $conditions[] = $sql;
            array_push($variables, ...$vars);
        }
        if ($pathPrefix !== null && $pathPrefix !== '') {
            $conditions[] = '`path` LIKE ?';
            $variables[] = rtrim($pathPrefix, '/') . '/%';
        }

        /** @var DataObject\Listing\Concrete $listing */
        $listing = new $listingClass();
        $listing->setUnpublished($includeUnpublished);
        if ($conditions !== []) {
            $listing->setCondition(implode(' AND ', $conditions), $variables);
        }
        if ($orderBy !== null && $orderBy !== '') {
            if (!\in_array($orderBy, $allowedFields, true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown orderBy field "%s".', $orderBy));
            }
            $listing->setOrderKey($orderBy);
            $listing->setOrder(\in_array(strtoupper($orderDir), self::ORDER_DIRECTIONS, true) ? strtoupper($orderDir) : 'ASC');
        }

        $total = $listing->getTotalCount();
        if ($limit > 0) {
            $listing->setLimit($limit);
        }
        if ($offset > 0) {
            $listing->setOffset($offset);
        }

        return ['items' => $listing->load(), 'total' => $total];
    }

    /**
     * @return array{items: \Pimcore\Model\Element\ElementInterface[], total: int}
     */
    public function findElements(
        string $elementType,
        ?string $pathPrefix,
        ?string $nodeType,
        ?string $nameLike,
        bool $includeUnpublished,
        int $limit,
        int $offset,
    ): array {
        $isDocument = $elementType === 'document';
        $listing = $isDocument ? new Document\Listing() : new Asset\Listing();
        $nameColumn = $isDocument ? '`key`' : 'filename';

        $conditions = [];
        $variables = [];
        if ($pathPrefix !== null && $pathPrefix !== '') {
            $conditions[] = '`path` LIKE ?';
            $variables[] = rtrim($pathPrefix, '/') . '/%';
        }
        if ($nodeType !== null && $nodeType !== '') {
            $conditions[] = '`type` = ?';
            $variables[] = $nodeType;
        }
        if ($nameLike !== null && $nameLike !== '') {
            $conditions[] = $nameColumn . ' LIKE ?';
            $variables[] = '%' . $nameLike . '%';
        }

        if ($conditions !== []) {
            $listing->setCondition(implode(' AND ', $conditions), $variables);
        }
        if ($isDocument && method_exists($listing, 'setUnpublished')) {
            $listing->setUnpublished($includeUnpublished);
        }

        $total = $listing->getTotalCount();
        if ($limit > 0) {
            $listing->setLimit($limit);
        }
        if ($offset > 0) {
            $listing->setOffset($offset);
        }

        return ['items' => $listing->load(), 'total' => $total];
    }

    /**
     * @param array{field: string, operator: string, value?: mixed} $filter
     * @param string[]                                               $allowedFields
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildCondition(array $filter, array $allowedFields): array
    {
        $field = (string) ($filter['field'] ?? '');
        // Word operators (LIKE, IN, IS NULL, …) are upper-cased; symbol operators pass through.
        $operator = strtoupper(trim((string) ($filter['operator'] ?? '=')));

        if (!\in_array($field, $allowedFields, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown or unfilterable field "%s".', $field));
        }
        if (!isset(self::OPERATORS[$operator])) {
            throw new \InvalidArgumentException(\sprintf('Unsupported operator "%s". Allowed: %s.', $operator, implode(', ', array_keys(self::OPERATORS))));
        }

        $column = '`' . $field . '`';
        $kind = self::OPERATORS[$operator];

        if ($kind === 'none') {
            return [$column . ' ' . $operator, []];
        }
        if ($kind === 'multi') {
            $value = $filter['value'] ?? [];
            if (!\is_array($value) || $value === []) {
                throw new \InvalidArgumentException(\sprintf('Operator "%s" requires a non-empty array value.', $operator));
            }
            $placeholders = implode(', ', array_fill(0, \count($value), '?'));

            return [$column . ' ' . $operator . ' (' . $placeholders . ')', array_values($value)];
        }

        if (!\array_key_exists('value', $filter)) {
            throw new \InvalidArgumentException(\sprintf('Operator "%s" requires a value.', $operator));
        }

        return [$column . ' ' . $operator . ' ?', [$filter['value']]];
    }
}
