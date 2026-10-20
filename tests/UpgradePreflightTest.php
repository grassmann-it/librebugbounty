<?php

namespace App\Service {
    // Model UID 0 without requiring privileged fixture creation. All other
    // ownership and permission checks use the real filesystem.
    function fileowner(string $path): int|false
    {
        return isset(\App\Tests\UpgradePreflightTest::$rootOwned[$path]) ? 0 : \fileowner($path);
    }
}

namespace App\Tests {
    use App\Command\UpgradePreflightCommand;
    use App\Service\UpgradePreflightService;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Tester\CommandTester;
    use Symfony\Component\Filesystem\Filesystem;
    use Symfony\Component\Process\Process;

    final class UpgradePreflightTest extends TestCase
    {
        public static array $rootOwned = [];
        private string $directory;

        protected function setUp(): void
        {
            $this->directory = sys_get_temp_dir().'/lbb-upgrade-'.bin2hex(random_bytes(8));
            (new Filesystem())->mkdir([
                $this->directory.'/playwright-worker/node_modules/package',
                $this->directory.'/var/cache',
                $this->directory.'/var/log',
            ]);
            file_put_contents($this->directory.'/playwright-worker/node_modules/package/index.js', 'fixture');
            file_put_contents($this->directory.'/var/cache/keep', 'existing runtime state');
        }

        protected function tearDown(): void
        {
            self::$rootOwned = [];
            @chmod($this->directory.'/var', 0755);
            (new Filesystem())->remove($this->directory);
        }

        public function testHealthyCommandIsReadOnly(): void
        {
            if (\fileowner($this->directory) === 0) {
                self::markTestSkipped('Healthy fixture requires a non-root owner.');
            }
            $before = $this->manifest();
            $tester = $this->command();
            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('Read-only upgrade preflight', $tester->getDisplay());
            self::assertSame($before, $this->manifest());
        }

        public function testNestedRootOwnedDependencyBlocksWithScopedManualRemediation(): void
        {
            self::$rootOwned[$this->directory.'/playwright-worker/node_modules/package/index.js'] = true;
            $before = $this->manifest();
            $tester = $this->command();
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('[blocked] playwright-worker/node_modules: Root-owned', $tester->getDisplay());
            self::assertStringContainsString('Stop DDEV/workers', $tester->getDisplay());
            self::assertStringContainsString('README.md#upgrade-preflight', $tester->getDisplay());
            self::assertSame($before, $this->manifest(), 'No repair, cache creation or state changes.');
        }

        public function testUnwritableVarIsDiagnosedWithoutChangingPermissions(): void
        {
            chmod($this->directory.'/var', 0500);
            clearstatcache();
            if (is_writable($this->directory.'/var')) {
                self::markTestSkipped('This process bypasses filesystem write permissions.');
            }
            $before = $this->manifest();
            $tester = $this->command();
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('[blocked] var:', $tester->getDisplay());
            self::assertStringContainsString('application user', $tester->getDisplay());
            self::assertSame($before, $this->manifest());
        }

        public function testMissingRuntimePathsAreNotCreatedOrReportedAsProvenWritable(): void
        {
            (new Filesystem())->remove($this->directory.'/var');
            $tester = $this->command();
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('[unknown] var: Runtime directory is absent', $tester->getDisplay());
            self::assertDirectoryDoesNotExist($this->directory.'/var');
        }

        public function testStandaloneEntryPointDiagnosesUnwritableVarWithoutKernelBoot(): void
        {
            mkdir($this->directory.'/bin');
            copy(dirname(__DIR__).'/bin/upgrade-preflight.php', $this->directory.'/bin/upgrade-preflight.php');
            symlink(dirname(__DIR__).'/vendor', $this->directory.'/vendor');
            chmod($this->directory.'/var', 0500);
            clearstatcache();
            if (is_writable($this->directory.'/var')) {
                self::markTestSkipped('This process bypasses filesystem write permissions.');
            }
            $before = $this->manifest();
            $process = new Process([PHP_BINARY, $this->directory.'/bin/upgrade-preflight.php']);
            self::assertSame(Command::FAILURE, $process->run());
            self::assertSame('', $process->getErrorOutput());
            self::assertStringContainsString('[blocked] var:', $process->getOutput());
            self::assertSame($before, $this->manifest());
        }

        public function testOptionalMissingLogDirectoryIsNotABlocker(): void
        {
            (new Filesystem())->remove($this->directory.'/var/log');
            $results = (new UpgradePreflightService($this->directory))->inspect();
            self::assertNotContains('var/log', array_column($results, 'path'));
            self::assertDirectoryDoesNotExist($this->directory.'/var/log');
        }

        public function testRuntimeSymlinkDoesNotCertifyItsChildren(): void
        {
            (new Filesystem())->remove($this->directory.'/var');
            symlink('/tmp', $this->directory.'/var');
            $results = (new UpgradePreflightService($this->directory))->inspect();
            foreach (array_slice($results, 1) as $result) {
                self::assertSame('unknown', $result['state']);
            }
        }

        public function testMissingDependenciesAreNormalAndNeverInstalled(): void
        {
            (new Filesystem())->remove($this->directory.'/playwright-worker/node_modules');
            $result = (new UpgradePreflightService($this->directory))->inspect()[0];
            self::assertSame('ok', $result['state']);
            self::assertStringContainsString('absent', $result['message']);
            self::assertDirectoryDoesNotExist($this->directory.'/playwright-worker/node_modules');
        }

        public function testDependencySymlinkIsNotFollowed(): void
        {
            (new Filesystem())->remove($this->directory.'/playwright-worker/node_modules');
            symlink('/usr', $this->directory.'/playwright-worker/node_modules');
            $result = (new UpgradePreflightService($this->directory))->inspect()[0];
            self::assertSame('unknown', $result['state']);
            self::assertStringContainsString('not inspected', $result['message']);
        }

        public function testInspectionLimitNeverClaimsAllDependenciesAreHealthy(): void
        {
            if (\fileowner($this->directory) === 0) {
                self::markTestSkipped('Bound fixture requires a non-root owner.');
            }
            for ($i = 0; $i < 2001; ++$i) {
                touch($this->directory.'/playwright-worker/node_modules/package/file-'.$i);
            }
            $result = (new UpgradePreflightService($this->directory))->inspect()[0];
            self::assertSame('unknown', $result['state']);
            self::assertStringContainsString('2000-entry limit', $result['message']);
        }

        private function command(): CommandTester
        {
            return new CommandTester(new UpgradePreflightCommand(new UpgradePreflightService($this->directory)));
        }

        private function manifest(): array
        {
            $manifest = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $path) {
                $manifest[(string) $path] = [fileperms($path), \fileowner($path), filemtime($path), is_file($path) ? hash_file('sha256', $path) : null];
            }
            ksort($manifest);

            return $manifest;
        }
    }
}
