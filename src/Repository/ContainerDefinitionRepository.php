<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Repository;

use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Objectbrick;

/**
 * Thin access layer over Pimcore's field collection and object brick definitions.
 */
final class ContainerDefinitionRepository
{
    /**
     * @return Fieldcollection\Definition[]
     */
    public function allFieldCollections(): array
    {
        return (new Fieldcollection\Definition\Listing())->load();
    }

    public function findFieldCollection(string $key): ?Fieldcollection\Definition
    {
        return Fieldcollection\Definition::getByKey($key);
    }

    /**
     * @return Objectbrick\Definition[]
     */
    public function allObjectBricks(): array
    {
        return (new Objectbrick\Definition\Listing())->load();
    }

    public function findObjectBrick(string $key): ?Objectbrick\Definition
    {
        return Objectbrick\Definition::getByKey($key);
    }
}
