<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class PimcoreMcpBundle extends Bundle
{
    /**
     * The bundle class lives in src/, but the bundle root (holding config/) is
     * one level up. Point Symfony at the root so conventional paths resolve.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
