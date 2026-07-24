<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Command;

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the Pimcore MCP server over the stdio transport.
 *
 * An MCP client (Claude Code / Claude Desktop, …) launches this command and
 * speaks JSON-RPC 2.0 over stdin/stdout. Because it runs inside the Pimcore
 * Symfony kernel, all DataObject definitions are available to the tools.
 *
 * IMPORTANT: stdout is the JSON-RPC channel — nothing else may be written to
 * it. Diagnostics therefore go to stderr (or nowhere), never to $output.
 */
#[AsCommand(
    name: 'pimcore:mcp:serve',
    description: 'Start the Pimcore MCP server (stdio transport) for schema introspection.',
)]
final class McpServerCommand extends Command
{
    private const SERVER_NAME = 'pimcore-mcp';
    private const SERVER_VERSION = '0.1.0';

    /**
     * @param ContainerInterface $toolLocator PSR-11 locator holding the MCP tool
     *                                         services keyed by their FQCN, used
     *                                         by the SDK to resolve tool handlers.
     */
    public function __construct(
        private readonly ContainerInterface $toolLocator,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Bundle root: this file lives at <root>/src/Command/McpServerCommand.php.
        $bundleRoot = \dirname(__DIR__, 2);

        $server = Server::builder()
            ->setServerInfo(self::SERVER_NAME, self::SERVER_VERSION)
            ->setContainer($this->toolLocator)
            ->setLogger($this->logger ?? new NullLogger())
            ->setDiscovery($bundleRoot, ['src/Tool'])
            ->build();

        $server->run(new StdioTransport());

        return Command::SUCCESS;
    }
}
