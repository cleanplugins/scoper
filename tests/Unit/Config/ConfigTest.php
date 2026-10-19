<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Config;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Path;
use CleanPlugins\Scoper\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testResolvesDefaultsAndPaths(): void
    {
        $config = $this->config(
            [],
            [
                'version' => '1.2.3',
                'config' => ['vendor-dir' => 'lib/vendor'],
            ],
        );

        self::assertSame(Path::normalize($this->tmp), $config->projectDir);
        self::assertSame('lib/vendor', $config->vendor);
        self::assertSame('dist', $config->dist);
        self::assertSame('build', $config->output);
        self::assertSame('.build', $config->artifacts);
        self::assertSame('releases', $config->releases);
        self::assertSame(['src'], $config->source);
        self::assertNull($config->assetsCommand);
        self::assertSame('warn', $config->assetsCheck);
        self::assertSame('project', $config->releaseName);
        self::assertNull($config->releaseVersion);
        self::assertSame(Path::join($this->tmp, 'build'), $config->outputPath());
        self::assertSame(Path::join($this->tmp, 'lib/vendor'), $config->vendorPath());
    }

    public function testMergesBuildUnderScoper(): void
    {
        $this->write(
            'composer.json',
            json_encode(
                [
                    'name' => 'acme/project',
                    'extra' => [
                        'build' => [
                            'prefix' => 'Vendor\\Build',
                            'source' => ['src'],
                            'output' => 'from-build',
                        ],
                        'scoper' => [
                            'output' => 'from-scoper',
                        ],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $config = Config::load($this->tmp);

        self::assertSame('Vendor\\Build', $config->prefix->namespace);
        self::assertSame('from-scoper', $config->output);
    }

    public function testReadsAssetsComposerVerifyAndReleaseSettings(): void
    {
        $config = $this->config([
            'assets' => [
                'command' => ['npm run build', 'npm run check'],
                'sources' => ['src', 'vite.config.js'],
                'check' => 'fail',
            ],
            'composer' => [
                'args' => ['--prefer-dist'],
            ],
            'verify' => [
                'probes' => 'build/probes.php',
                'own' => ['src', 'includes'],
            ],
            'release' => [
                'name' => 'acme-release',
                'version' => [
                    'file' => 'plugin.php',
                    'constant' => 'ACME_VERSION',
                    'header' => 'Version',
                ],
            ],
        ]);

        self::assertSame(['npm run build', 'npm run check'], $config->assetsCommand);
        self::assertSame(['src', 'vite.config.js'], $config->assetsSources);
        self::assertSame('fail', $config->assetsCheck);
        self::assertSame(['--prefer-dist'], $config->composerArgs);
        self::assertSame('build/probes.php', $config->probes);
        self::assertSame(['src', 'includes'], $config->own);
        self::assertSame('acme-release', $config->releaseName);
        self::assertSame('plugin.php', $config->releaseVersion['file'] ?? null);
    }

    public function testRejectsUnknownSettings(): void
    {
        $this->expectException(ScoperException::class);

        $this->config(['unknown' => true]);
    }

    public function testRejectsAbsoluteSourcePaths(): void
    {
        $this->expectException(ScoperException::class);

        $this->config(['source' => ['C:/outside']]);
    }

    public function testRejectsArtifactsInsideOutput(): void
    {
        $this->expectException(ScoperException::class);

        $this->config([
            'output' => 'build',
            'artifacts' => 'build/.build',
        ]);
    }

    public function testRejectsSourceOutputOverlap(): void
    {
        $this->expectException(ScoperException::class);

        $this->config([
            'source' => ['build/src'],
            'output' => 'build',
        ]);
    }

    public function testRejectsInvalidAssetsCheckMode(): void
    {
        $this->expectException(ScoperException::class);

        $this->config(['assets' => ['check' => 'sometimes']]);
    }

    public function testRejectsReleaseVersionWithoutAReader(): void
    {
        $this->expectException(ScoperException::class);

        $this->config([
            'release' => [
                'version' => ['file' => 'plugin.php'],
            ],
        ]);
    }
}
