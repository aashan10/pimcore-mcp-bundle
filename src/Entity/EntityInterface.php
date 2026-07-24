<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Entity;

interface EntityInterface
{
    public function getId(): mixed;

    public function toArray(): array;
}
