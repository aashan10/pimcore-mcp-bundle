<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Generator\AreabrickGenerator;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP tool for scaffolding Pimcore document areabricks.
 */
final class AreabrickTool
{
    public function __construct(
        private readonly AreabrickGenerator $generator,
    ) {}

    /**
     * Scaffold a document areabrick: creates the PHP brick class
     * (src/Document/Areabrick) and its Twig view template
     * (templates/areas/<brick-id>). The brick auto-registers via container
     * autoconfiguration.
     *
     * @param string      $name        PascalCase class name, e.g. "TeaserRow".
     * @param string|null $description Shown in the admin area chooser.
     * @param string|null $editables   JSON array of editables to prefill the template, e.g.
     *                                 [{"type":"input","name":"headline"},{"type":"wysiwyg","name":"body"}].
     *                                 "type" is the pimcore editable (input, textarea, wysiwyg, image, link, …).
     * @param bool        $force       Overwrite existing files.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'generate_areabrick',
        description: 'Scaffold a Pimcore document areabrick: generates the PHP brick class and its Twig view template (with optional prefilled editables). The brick auto-registers; clear the cache to see it in the admin.',
    )]
    public function generateAreabrick(
        string $name,
        ?string $description = null,
        ?string $editables = null,
        bool $force = false,
    ): array {
        $decodedEditables = [];
        if ($editables !== null && $editables !== '') {
            $decoded = json_decode($editables, true);
            if (!\is_array($decoded)) {
                return ['error' => 'The "editables" argument must be a JSON array of {type, name} objects.'];
            }
            $decodedEditables = $decoded;
        }

        try {
            $result = $this->generator->generate($name, $description, $decodedEditables, $force);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => \sprintf('Failed to generate areabrick: %s', $e->getMessage())];
        }

        return [
            'generated' => true,
            'brickId' => $result['brickId'],
            'className' => $result['className'],
            'files' => $result['files'],
            '_note' => 'Areabrick auto-registers via autoconfiguration. Run a cache clear '
                . '(bin/console pimcore:cache:clear) to see it in the admin area chooser.',
        ];
    }
}
