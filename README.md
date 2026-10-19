# Scoper

[![Tests](https://github.com/cleanplugins/scoper/actions/workflows/tests.yml/badge.svg)](https://github.com/cleanplugins/scoper/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Scoper builds a self-contained production tree for a Composer project:

- installs production dependencies from the project lock file;
- copies and stages the project's own files;
- removes configured documentation, binaries, packages, paths and string literals;
- prefixes every dependency namespace and global symbol so two plugins on the same site cannot collide;
- generates a classmap autoloader from Composer's own installation metadata;
- verifies the staged tree in a clean PHP process;
- optionally creates a release zip.

It is a Composer plugin and a standalone CLI. The build is designed for CI: it is deterministic, reports every phase, and fails loudly when the tree is not safe to ship.

## Requirements

- PHP 8.3 or newer
- Composer 2
- `ext-json`, `ext-tokenizer`, `ext-zip`
- Node/npm only if the project config declares an asset command

## Installation

Once the package is published on Packagist:

```bash
composer require --dev cleanplugins/scoper
```

Until then, install it from the public repository:

```bash
composer config repositories.scoper vcs https://github.com/cleanplugins/scoper.git
composer require --dev cleanplugins/scoper:^0.1
```

Allow the plugin when Composer asks, or add it to the project manifest:

```json
{
    "config": {
        "allow-plugins": {
            "cleanplugins/scoper": true
        }
    }
}
```

The package is a development dependency. A release build runs `composer install --no-dev`, so the scoper is never part of the shipped tree.

## Quick start

Add the build configuration to the project's `composer.json`:

```json
{
    "name": "acme/example-plugin",
    "require": {
        "acme/library": "^1.0"
    },
    "extra": {
        "scoper": {
            "prefix": "Acme\\Build",
            "source": ["plugin.php", "includes", "assets"],
            "vendor": "vendor",
            "dist": "assets",
            "output": "build",
            "artifacts": "var/scoper",
            "releases": "releases"
        }
    }
}
```

Build the staged tree:

```bash
composer scoper:build
```

Create the release zip as well:

```bash
composer scoper:build --release
```

The standalone binary is available without Composer commands:

```bash
vendor/bin/scoper scoper:build
vendor/bin/scoper scoper:build --release
```

## Command options

| Option          | Description                                                                     |
| --------------- | ------------------------------------------------------------------------------- |
| `--release`     | Also write `releases/<release.name>-<version>.zip`.                             |
| `--no-assets`   | Reuse the existing `dist` directory instead of running the asset command.       |
| `--stream`      | Stream asset and Composer output to the terminal as it runs.                    |
| `-v`            | Print the aligned detail sections for every phase.                              |
| `-q`            | Only print the phase summaries and the final result.                            |
| `--no-ansi`     | Disable colors and animated progress.                                           |
| `--working-dir` | Project directory for the standalone binary; defaults to the current directory. |

On a TTY, phases with a known total show a real progress bar; other phases show a spinner with elapsed time. Redirected output, `-q`, `--no-ansi` and CI suppress both.

## How it works

1. **Prerequisites** — validates the release guards and reads the project version.
2. **Clean output** — empties the configured output directory.
3. **Assets** — optionally runs the configured asset command and checks freshness.
4. **Dependencies** — installs production dependencies into the output directory from the project lock file.
5. **Stage source files** — copies the configured source entries.
6. **Removals** — applies the `remove` policy before scoping.
7. **Scope namespaces** — inventories declarations, plans every replacement, verifies the rewritten PHP, then applies it.
8. **Generate autoloader** — writes a classmap loader for the scoped tree.
9. **Verify tree** — loads the staged tree in a clean PHP process and runs the configured probes.
10. **Finalise** — removes Composer metadata and VCS files.
11. **Package release** — optionally writes the release zip.

The shipper never contains `composer.json` or `composer.lock`. Composer's own autoloader is not shipped either; the generated classmap is the only loader.

## Configuration

All settings live under `extra.scoper`. `extra.build` is also read and merged underneath `extra.scoper`; it exists so an older policy-only configuration can keep working.

| Key               | Default                         | Description                                                                      |
| ----------------- | ------------------------------- | -------------------------------------------------------------------------------- |
| `prefix`          | required                        | Namespace prefix or object with `namespace` and optional `global` styles.        |
| `source`          | required                        | Files and directories copied into the output tree.                               |
| `vendor`          | `config.vendor-dir` or `vendor` | Project vendor directory.                                                        |
| `dist`            | `dist`                          | Built asset directory checked or copied by the build.                            |
| `output`          | `build`                         | Staged production tree.                                                          |
| `artifacts`       | `.build`                        | Build metadata: prefix map, installed package list, log and verify config.       |
| `releases`        | `releases`                      | Release zip directory.                                                           |
| `assets.command`  | `null`                          | String or list of shell commands run before staging.                             |
| `assets.sources`  | `[]`                            | Files and directories whose modification time is compared with `dist`.           |
| `assets.check`    | `warn`                          | `off`, `warn` or `fail`.                                                         |
| `composer.args`   | `[]`                            | Extra arguments appended to `composer install`.                                  |
| `verify.probes`   | `null`                          | PHP file returning per-package probe callables.                                  |
| `verify.own`      | autoload roots                  | Project roots that must remain byte-identical while their imports are scoped.    |
| `release.name`    | composer package name           | Base name of the release zip.                                                    |
| `release.version` | composer `version`              | Object with `file`, `constant` and/or `header` used to read the shipped version. |

All paths are relative to the project and must not escape it. `source` may not overlap `output` or `artifacts`, and `artifacts` may not sit inside `output`.

### Prefix

`prefix` may be a namespace string:

```json
{ "prefix": "Acme\\Build" }
```

or an object with a namespace and global styles:

```json
{
    "prefix": {
        "namespace": "Acme\\Build",
        "global": {
            "base": "Acme Build",
            "snake_pascal": "Acme_Build_"
        }
    }
}
```

The available global styles are `screaming_snake`, `snake_pascal`, `snake_case`, `pascal` and `camel`. An explicit style wins; otherwise the style is derived from `global.base`, then from the namespace. The namespace style also decides how global symbols are renamed.

### Policy

`ignore`, `remove` and `override` are lists of entries. Every entry needs `type`, `value` and `reason`:

```json
{
    "extra": {
        "scoper": {
            "prefix": "Acme\\Build",
            "source": ["plugin.php", "includes"],
            "ignore": [
                {
                    "type": "package",
                    "value": "symfony/polyfill-*",
                    "reason": "Keep PHP extension fallback names global."
                },
                {
                    "type": "string",
                    "value": "Legacy_",
                    "package": "acme/library",
                    "reason": "Introspection literal; the package depends on the exact prefix."
                }
            ],
            "remove": [
                {
                    "type": "path",
                    "value": "vendor/**/*.md",
                    "exclude": "license*",
                    "reason": "Documentation is not needed at runtime; licences must ship."
                }
            ]
        }
    }
}
```

Supported types:

- `ignore`: `package`, `string`, `namespace`, `path`, `global`
- `remove`: `package`, `string`, `path`
- `override`: `namespace`, `global`

A `package` field qualifies a `string` or `path` entry. Package names may wildcard the name, for example `symfony/polyfill-*`, but never the vendor. `exclude` on a removed path matches file names only.

Glob semantics:

- `*` matches one path segment.
- `**` matches any characters.
- `**/` matches zero or more whole segments.
- Matching is case-insensitive.
- A pattern naming a directory covers everything below it.

`override` wins over `ignore`; a package-qualified entry wins over an unqualified one; the longest namespace match wins. Duplicate decisions and entries that match nothing are reported.

### Release version

```json
{
    "release": {
        "name": "acme-plugin",
        "version": {
            "file": "plugin.php",
            "constant": "ACME_PLUGIN_VERSION",
            "header": "Version"
        }
    }
}
```

Scoper reads the constant and the plugin header, rejects a mismatch, and names the archive `<name>-<version>.zip`. Without `release.version`, a top-level `version` in `composer.json` is used when present.

Set `SCOPER_ALLOW_DIRTY=1` to package uncommitted changes for a local snapshot. Without it, `--release` requires a clean Git working tree.

## Assets

The asset command runs in the project root:

```json
{
    "assets": {
        "command": "npm run build",
        "sources": ["src", "vite.config.js"],
        "check": "warn"
    }
}
```

When `--no-assets` is used, the command is skipped and the existing `dist` is reused. The freshness check compares the newest configured source against the newest `dist` file.

## Probes

Probes call the scoped code after it has been loaded in a clean process. A probe file returns a map of package name to callable:

```php
<?php

return [
    'acme/library' => static function (array $config): string {
        $class = $config['prefix'] . '\\Acme\\Library\\Compiler';

        if (!class_exists($class)) {
            throw new RuntimeException("{$class} did not autoload");
        }

        return 'Compiler class loads';
    },
];
```

The callable receives the normalized scoper configuration. Probes run only for packages present in the staged tree.

## CI example

```yaml
- uses: shivammathur/setup-php@v2
  with:
      php-version: '8.3'
      extensions: zip
- run: composer install --no-interaction --no-progress
- run: composer scoper:build --release
```

Use `--no-ansi` or redirect output when the CI log should not contain progress sequences.

## Development

```bash
composer install
composer test
composer test:coverage
```

The test suite has unit tests and fixture-based integration tests under `tests/`. Build tests use temporary directories and do not modify the repository.

The package is split into these areas:

- `Config` — manifest loading, policy and prefix resolution.
- `Build` — the phase pipeline, version reading and release packaging.
- `Prune`, `Scope`, `Autoload`, `Verify` — the build stages.
- `Console` — commands, progress reporting and the shared runner.
- `Support` — filesystem, path, process and formatting helpers.

## License

MIT. See [LICENSE](LICENSE).
