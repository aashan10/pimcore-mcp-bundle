<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Entity\Definitions;

/**
 * The kinds of reusable field container Pimcore supports alongside classes.
 */
enum ContainerType: string
{
    case FIELD_COLLECTION = 'fieldcollection';
    case OBJECT_BRICK = 'objectbrick';
}
