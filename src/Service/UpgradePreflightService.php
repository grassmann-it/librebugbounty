<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Read-only diagnostics, scoped to generated worker dependencies and runtime paths. */
final class UpgradePreflightService
{
    private const MAX_ENTRIES = 2000;
    private const DOC = 'README.md#upgrade-preflight';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory,
    ) {
    }

    /** @return list<array{path: string, state: string, message: string, remedy: string, doc: string}> */
    public function inspect(): array
    {
        $results = [];
        $relative = 'playwright-worker/node_modules';
        $path = $this->projectDirectory.'/'.$relative;
        $state = 'ok';
        $message = 'No root-owned worker dependency entries found.';
        if (is_link($path)) {
            $state = 'unknown';
            $message = 'Worker dependencies are a symbolic link; the target was not inspected.';
        } elseif (!file_exists($path)) {
            $message = 'Worker dependencies are absent; startup will install them from the lockfile.';
        } else {
            try {
                $paths = (static function () use ($path): \Generator {
                    yield $path;
                    yield from new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::SELF_FIRST,
                    );
                })();
                $count = 0;
                foreach ($paths as $entry) {
                    if (++$count > self::MAX_ENTRIES) {
                        $state = 'unknown';
                        $message = 'Dependency inspection reached its 2000-entry limit; ownership is not fully checked.';
                        break;
                    }
                    $entry = (string) $entry;
                    // Never follow symlinks, including .bin links into dependencies.
                    if (is_link($entry)) {
                        continue;
                    }
                    $owner = @fileowner($entry);
                    if ($owner === 0) {
                        $state = 'blocked';
                        $message = 'Root-owned worker dependencies can prevent removal or replacement during upgrade.';
                        break;
                    }
                    if ($owner === false) {
                        throw new \RuntimeException('Ownership unavailable.');
                    }
                }
            } catch (\Throwable) {
                $state = 'unknown';
                $message = 'Worker dependency ownership could not be fully inspected.';
            }
        }
        $results[] = $this->result($relative, $state, $message,
            'Stop DDEV/workers, confirm the workspace path, and remove only generated playwright-worker/node_modules using the upgrade guide. Ask the administrator if ordinary removal fails.');

        foreach (['var', 'var/cache', 'var/log'] as $relative) {
            $path = $this->projectDirectory.'/'.$relative;
            // Cache/log directories are optional until the deployment creates
            // them. Their absence is not a permission defect in an existing var.
            if ($relative !== 'var' && !file_exists($path) && !is_link($path)
                && !is_link($this->projectDirectory.'/var')) {
                continue;
            }
            if (is_link($this->projectDirectory.'/var') || is_link($path)) {
                $state = 'unknown';
                $message = 'Runtime path is a symbolic link; verify its target and access manually.';
            } elseif (!file_exists($path)) {
                $state = 'unknown';
                $message = 'Runtime directory is absent; creation and runtime access have not been proven.';
            } else {
                $state = is_dir($path) && is_writable($path) && is_executable($path) ? 'ok' : 'blocked';
                $message = $state === 'ok'
                    ? 'Runtime directory is writable and searchable by the current process.'
                    : 'Runtime path is not a writable, searchable directory for the current process.';
            }
            $results[] = $this->result($relative, $state, $message,
                'Have the administrator verify the application user, owner/group and directory access for this var path only. Re-run as the application user; do not use recursive chmod 777 or change the whole checkout.');
        }

        return $results;
    }

    private function result(string $path, string $state, string $message, string $remedy): array
    {
        return compact('path', 'state', 'message', 'remedy') + ['doc' => self::DOC];
    }
}
