<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Repository;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\Document;
use Pimcore\Model\Element\ElementInterface;

/**
 * Resolves and browses Pimcore content elements (documents, assets, data objects).
 */
final class ElementRepository
{
    public const TYPES = ['document', 'asset', 'object'];

    /**
     * Normalise a caller-supplied element type to the canonical form.
     */
    public function normalizeType(string $type): ?string
    {
        return match (strtolower($type)) {
            'document', 'documents', 'doc' => 'document',
            'asset', 'assets' => 'asset',
            'object', 'objects', 'dataobject', 'data-object', 'data_object' => 'object',
            default => null,
        };
    }

    public function resolve(string $type, ?int $id, ?string $path): ?ElementInterface
    {
        if ($id !== null) {
            return match ($type) {
                'document' => Document::getById($id),
                'asset' => Asset::getById($id),
                'object' => DataObject::getById($id),
                default => null,
            };
        }

        if ($path !== null && $path !== '') {
            return match ($type) {
                'document' => Document::getByPath($path),
                'asset' => Asset::getByPath($path),
                'object' => DataObject::getByPath($path),
                default => null,
            };
        }

        return null;
    }

    /**
     * @return array{items: ElementInterface[], total: int}
     */
    public function children(string $type, int $parentId, bool $includeUnpublished, int $limit, int $offset): array
    {
        $listing = match ($type) {
            'document' => new Document\Listing(),
            'asset' => new Asset\Listing(),
            'object' => new DataObject\Listing(),
            default => null,
        };

        if ($listing === null) {
            return ['items' => [], 'total' => 0];
        }

        $listing->setCondition('parentId = ?', [$parentId]);
        if (method_exists($listing, 'setUnpublished')) {
            $listing->setUnpublished($includeUnpublished);
        }
        $listing->setOrderKey('id');
        $listing->setOrder('asc');

        $total = $listing->getTotalCount();

        if ($limit > 0) {
            $listing->setLimit($limit);
        }
        if ($offset > 0) {
            $listing->setOffset($offset);
        }

        /** @var ElementInterface[] $items */
        $items = $listing->load();

        return ['items' => $items, 'total' => $total];
    }
}
