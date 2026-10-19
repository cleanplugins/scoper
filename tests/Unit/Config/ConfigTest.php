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
        self::assertNull($config->pluginFile);
        self::assertSame(Path::join($this->tmp, 'build'), $config->outputPath());
        self::assertSame(Path::join($this->tmp, 'lib/vendor'), $config->vendorPath());
    }

    public function testRejectsLegacyExtraBuild(): void
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
                        ],
                        'scoper' => [
                            'output' => 'build',
                        ],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $this->expectException(ScoperException::class);
        $this->expectExceptionMessage('extra.build');

        Config::load($this->tmp);
    }

    public function testRejectsTopLevelSettings(): void
    {
        $this->write(
            'composer.json',
            json_encode(
                [
                    'name' => 'acme/project',
                    'build' => [
                        'prefix' => 'Vendor\\Build',
                        'source' => ['src'],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $this->expectException(ScoperException::class);
        $this->expectExceptionMessage('top-level build/scoper');

        Config::load($this->tmp);
    }

    public function testRequiresExtraScoper(): void
    {
        $this->write(
            'composer.json',
            json_encode(
                [
                    'name' => 'acme/project',
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $this->expectException(ScoperException::class);
        $this->expectExceptionMessage('No scoper configuration found under extra.scoper');

        Config::load($this->tmp);
    }

    public function testRejectsABareConfigurationFile(): void
    {
        $this->write(
            'composer.json',
            json_encode(
                [
                    'prefix' => 'Vendor\\Build',
                    'source' => ['src'],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $this->expectException(ScoperException::class);

        Config::load($this->tmp);
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
                'plugin_file' => 'plugin.php',
            ],
        ]);

        self::assertSame(['npm run build', 'npm run check'], $config->assetsCommand);
        self::assertSame(['src', 'vite.config.js'], $config->assetsSources);
        self::assertSame('fail', $config->assetsCheck);
        self::assertSame(['--prefer-dist'], $config->composerArgs);
        self::assertSame('build/probes.php', $config->probes);
        self::assertSame(['src', 'includes'], $config->own);
        self::assertSame('acme-release', $config->releaseName);
        self::assertSame('plugin.php', $config->pluginFile);
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

    public function testRejectsLegacyReleaseVersion(): void
    {
        $this->expectException(ScoperException::class);
        $this->expectExceptionMessage('release.version is no longer supported');

        $this->config([
            'release' => [
                'version' => ['file' => 'plugin.php'],
            ],
        ]);
    }

    public function testRejectsAnEmptyPluginFile(): void
    {
        $this->expectException(ScoperException::class);

        $this->config([
            'release' => [
                'plugin_file' => '',
            ],
        ]);
    }

    public function testRejectsAnAbsolutePluginFile(): void
    {
        $this->expectException(ScoperException::class);

        $this->config([
            'release' => [
                'plugin_file' => 'C:/outside/plugin.php',
            ],
        ]);
    }
}
