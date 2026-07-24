<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Entity\Definitions;

/**
 * The two kinds of node that appear in a Pimcore definition tree.
 *
 * Mirrors Pimcore's own `datatype` discriminator on
 * {@see \Pimcore\Model\DataObject\ClassDefinition\Data} (data) and
 * {@see \Pimcore\Model\DataObject\ClassDefinition\Layout} (layout).
 */
enum FieldType: string
{
    /** A value-bearing field that becomes a getter/setter on the generated model. */
    case DATA = 'data';

    /** A structural/visual container (tab, panel, region, fieldset, …) with no value of its own. */
    case LAYOUT = 'layout';
}
