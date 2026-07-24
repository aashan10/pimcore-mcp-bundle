<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Maintenance;

use Symfony\Component\Process\Process;

/**
 * Runs a fixed allowlist of safe Pimcore maintenance console commands as
 * isolated subprocesses (never in the long-lived server process).
 *
 * Only the predefined actions below can run — no user-supplied command or
 * arguments are ever passed to the shell.
 */
final class MaintenanceRunner
{
    private const MAX_OUTPUT = 8000;
    private const DEFAULT_TIMEOUT = 300;
    private const MAX_TIMEOUT = 900;

    /**
     * action => console command (without the binary / bin/console).
     *
     * @var array<string, list<string>>
     */
    private const ACTIONS = [
        'clear_cache' => ['pimcore:cache:clear'],
        'warmup_cache' => ['cache:warmup'],
        'rebuild_classes' => ['pimcore:deployment:classes-rebuild'],
        'reindex_search' => ['pimcore:search-backend-reindex'],
    ];

    public function __construct(
        private readonly string $projectDir,
    ) {}

    /**
     * @return list<string>
     */
    public static function actions(): array
    {
        return array_keys(self::ACTIONS);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException on unknown action
     */
    public function run(string $action, int $timeout): array
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new \InvalidArgumentException(
                \sprintf('Unknown action "%s". Allowed: %s.', $action, implode(', ', self::actions())),
            );
        }

        $timeout = $timeout > 0 ? min($timeout, self::MAX_TIMEOUT) : self::DEFAULT_TIMEOUT;
        $command = array_merge(
            [\PHP_BINARY, $this->projectDir . '/bin/console'],
            self::ACTIONS[$action],
            ['--no-interaction'],
        );

        $process = new Process($command, $this->projectDir, null, null, (float) $timeout);
        $process->run();

        return [
            'action' => $action,
            'command' => implode(' ', self::ACTIONS[$action]),
            'success' => $process->isSuccessful(),
            'exitCode' => $process->getExitCode(),
            'output' => $this->truncate($process->getOutput()),
            'errorOutput' => $this->truncate($process->getErrorOutput()),
        ];
    }

    private function truncate(string $text): string
    {
        $text = trim($text);

        return \strlen($text) > self::MAX_OUTPUT
            ? substr($text, -self::MAX_OUTPUT) . "\n… [truncated, showing tail]"
            : $text;
    }
}
