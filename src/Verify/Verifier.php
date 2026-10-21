<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Verify;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs the build checks in a clean PHP process that has only the generated autoloader.
 *
 * The build process has the project's dev autoloader loaded; requiring the staged loader there
 * could redeclare the project's own autoload `files` or make an unscoped class reachable. The child
 * process (`verify-runner.php`) is the isolation boundary; this class passes it the paths and turns
 * its report back into a result or an exception.
 */
final class Verifier
{
    public function __construct(private readonly Config $config, private readonly Filesystem $files) {}

    public function run(?callable $tick = null): VerifyResult
    {
        $mapPath = $this->config->mapPath();
        $map = is_file($mapPath) ? (json_decode((string) file_get_contents($mapPath), true) ?: []) : [];
        $settings = [
            'classRoots' => $this->classRoots(),
            'ignoredPaths' => $map['excluded']['paths'] ?? $this->config->policy->ignoredPaths(),
            'probes' => $this->probeFiles(),
        ];
        $configPath = $this->config->artifactsPath() . '/verify-config.json';
        $this->files->write($configPath, json_encode($settings, JSON_UNESCAPED_SLASHES));
        $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'display_errors=stderr', __DIR__ . '/verify-runner.php', $configPath, $this->config->outputPath() . '/' . $this->config->vendor . '/autoload.php', $mapPath, $this->config->installedPath(), $this->config->outputPath(), $this->config->vendor]));

        $process = Process::fromShellCommandline($command, $this->config->projectDir, null, null, null);
        $process->setTimeout(null);
        $process->start();

        while ($process->isRunning()) {
            if ($tick !== null) {
                $tick();
            }

            usleep(100000);
        }

        $process->wait();
        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());
        $this->files->remove($configPath);

        if ($process->getExitCode() !== 0) {
            throw new ScoperException('Verification process failed', array_filter([$stderr, $stdout]));
        }

        $data = json_decode($stdout, true);

        if (!is_array($data)) {
            throw new ScoperException('Verification process returned invalid output', array_filter([$stdout, $stderr]));
        }

        $checks = (int) ($data['checks'] ?? 0);
        $failures = $data['failures'] ?? [];

        if ($failures !== []) {
            throw new ScoperException(sprintf('%d/%d checks failed', count($failures), $checks), $failures);
        }

        return new VerifyResult($checks, $data['lines'] ?? []);
    }

    /** @return array<string, string> */
    private function probeFiles(): array
    {
        $files = [];

        foreach ($this->config->probes as $package => $file) {
            $files[$package] = $this->config->absolute($file);
        }

        return $files;
    }

    /** @return list<string> */
    private function classRoots(): array
    {
        $autoload = $this->config->composerJson['autoload'] ?? [];
        $roots = [];

        foreach (['psr-4', 'psr-0'] as $type) {
            foreach ((array) ($autoload[$type] ?? []) as $entry) {
                foreach ((array) $entry as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $roots[] = trim(str_replace('\\', '/', $path), '/');
                    }
                }
            }
        }

        foreach ((array) ($autoload['classmap'] ?? []) as $path) {
            if (is_string($path) && trim($path) !== '') {
                $roots[] = trim(str_replace('\\', '/', $path), '/');
            }
        }

        foreach ((array) ($autoload['files'] ?? []) as $path) {
            if (is_string($path) && trim($path) !== '') {
                $roots[] = trim(str_replace('\\', '/', $path), '/');
            }
        }

        return array_values(array_unique($roots));
    }
}
