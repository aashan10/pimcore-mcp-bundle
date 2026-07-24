<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Documentation;

/**
 * Thrown by {@see DocumentationFetcher} for unsupported versions, invalid input
 * or network/parse failures. The tool layer turns these into a structured
 * `{error, _hint}` payload for the agent.
 */
final class DocumentationException extends \RuntimeException
{
}
