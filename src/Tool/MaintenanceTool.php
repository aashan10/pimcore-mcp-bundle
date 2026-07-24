<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Maintenance\MaintenanceRunner;
use Mcp\Capability\Attribute\McpTool;

/**
 * MCP tool for running safe Pimcore maintenance commands, so schema/content/
 * areabrick changes actually take effect.
 */
final class MaintenanceTool
{
    public function __construct(
        private readonly MaintenanceRunner $runner,
    ) {}

    /**
     * Run a safe Pimcore maintenance command (isolated subprocess).
     *
     * Actions:
     *  - clear_cache      → pimcore:cache:clear (see new areabricks/config, apply changes)
     *  - warmup_cache     → cache:warmup
     *  - rebuild_classes  → pimcore:deployment:classes-rebuild (rebuild DB + PHP classes after schema edits)
     *  - reindex_search   → pimcore:search-backend-reindex (rebuild admin search index)
     *
     * @param string $action  One of the actions above.
     * @param int    $timeout Max seconds to wait (default 300, max 900).
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'run_maintenance',
        description: 'Run a safe, allowlisted Pimcore maintenance command (clear_cache, warmup_cache, rebuild_classes, reindex_search) as an isolated subprocess. Use after add_field / generate_areabrick / content changes so they take effect.',
    )]
    public function runMaintenance(string $action, int $timeout = 300): array
    {
        try {
            return $this->runner->run($action, $timeout);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage(), 'allowedActions' => MaintenanceRunner::actions()];
        } catch (\Throwable $e) {
            return ['error' => \sprintf('Maintenance command failed to start: %s', $e->getMessage())];
        }
    }
}
