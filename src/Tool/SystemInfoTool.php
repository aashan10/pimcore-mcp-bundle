<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Pimcore\Model\Site;
use Pimcore\Tool;
use Pimcore\Tool\Admin;
use Pimcore\Version;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * MCP tool exposing high-level system/environment information to help an agent
 * orient itself in a Pimcore project.
 */
final class SystemInfoTool
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    /**
     * Get Pimcore/Symfony environment info: versions, environment, languages,
     * sites, enabled bundles and maintenance mode.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'system_info',
        description: 'Get high-level Pimcore system info: Pimcore & PHP version, environment/debug, valid languages, configured sites, enabled bundles and maintenance mode.',
    )]
    public function systemInfo(): array
    {
        return [
            'pimcoreVersion' => Version::getVersion(),
            'pimcoreRevision' => Version::getRevision(),
            'phpVersion' => \PHP_VERSION,
            'symfonyVersion' => \Symfony\Component\HttpKernel\Kernel::VERSION,
            'environment' => $this->kernel->getEnvironment(),
            'debug' => $this->kernel->isDebug(),
            'projectDir' => $this->kernel->getProjectDir(),
            'maintenanceMode' => $this->safeBool(static fn (): bool => Admin::isInMaintenanceMode()),
            'defaultLanguage' => Tool::getDefaultLanguage(),
            'validLanguages' => Tool::getValidLanguages(),
            'sites' => $this->sites(),
            'bundles' => $this->bundles(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sites(): array
    {
        try {
            $sites = [];
            foreach ((new Site\Listing())->load() as $site) {
                $sites[] = [
                    'id' => $site->getId(),
                    'mainDomain' => $site->getMainDomain(),
                    'rootId' => $site->getRootId(),
                    'rootPath' => $site->getRootPath(),
                ];
            }

            return $sites;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function bundles(): array
    {
        $names = array_keys($this->kernel->getBundles());
        sort($names);

        return $names;
    }

    private function safeBool(callable $fn): ?bool
    {
        try {
            return (bool) $fn();
        } catch (\Throwable) {
            return null;
        }
    }
}
