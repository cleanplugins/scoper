# Scoper

[![Package](https://img.shields.io/packagist/v/cleanplugins/scoper)](https://packagist.org/packages/cleanplugins/scoper)
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

## Why Scoper

Most scopers parse and reprint every PHP file. Scoper replaces only the name byte ranges that need renaming.

- line numbers, blank lines, comments, CRLF endings, indentation and formatting stay unchanged;
- a scoped file differs from its source only where a symbol was renamed;
- stack traces and `__LINE__` values still map to the original source;
- the result stays reviewable and diffs cleanly in CI.

## Requirements

- PHP 8.3 or newer
- Composer 2
- `ext-json`, `ext-tokenizer`, `ext-zip`
- Node/npm only if the project config declares an asset command

## Installation

Install the latest stable release:

```bash
composer require --dev cleanplugins/scoper
```

Install the development branch directly from GitHub:

```bash
composer config repositories.scoper vcs https://github.com/cleanplugins/scoper.git
composer require --dev cleanplugins/scoper:dev-main
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
| `-v`            | Print the aligned detail sections and unmatched policy entries for every phase.  |
| `-q`            | Only print the phase summaries and the final result.                            |
| `--no-ansi`     | Disable colors and animated progress.                                           |
| `--working-dir` | Project directory for the standalone binary; defaults to the current directory. |

On a TTY, phases with a known total show a real progress bar; other phases show a spinner with elapsed time. Redirected output, `-q`, `--no-ansi` and CI suppress both. Warnings are printed under the phase they belong to; policy entries that match nothing are only reported with `-v`.

## How it works

1. **Prerequisites** — validates the release guards and reads the project version.
2. **Clean output** — empties the configured output directory.
3. **Assets** — optionally runs the configured asset command and checks freshness.
4. **Dependencies** — installs production dependencies into the output directory from the project lock file.
5. **Stage source files** — copies the configured source entries.
6. **Removals** — applies the `remove` policy before scoping.
7. **Scope namespaces** — inventories declarations, splices only the name byte ranges, verifies syntax and survivors, then applies the edits. Files are never reprinted.
8. **Generate autoloader** — writes a classmap loader for the scoped tree.
9. **Verify tree** — compares the staged tree with the snapshot, rejects references to dev-only packages, then loads the tree in a clean PHP process and runs the configured probes.
10. **Finalise** — removes Composer metadata and VCS files.
11. **Package release** — optionally writes the release zip.

The shipper never contains `composer.json` or `composer.lock`. Composer's own autoloader is not shipped either; the generated classmap is the only loader.

The staged tree is copied to `<artifacts>/snapshot` before removals. The snapshot stays there for verification and diffing and is replaced by the next build. A reference in the shipped tree to a class or function provided only by a dev dependency fails the build, because `composer install --no-dev` would not ship it. String literals are scanned too, but only for declared dev-only symbols: a namespace-shaped string such as a hook name does not fail the build.

## Configuration

All settings live under `extra.scoper`. Other locations, including the retired `extra.build` key, are rejected.

| Key                   | Default                         | Description                                                                   |
| --------------------- | ------------------------------- | ----------------------------------------------------------------------------- |
| `prefix`              | required                        | Namespace prefix or object with `namespace` and optional `global` styles.     |
| `source`              | required                        | Files and directories copied into the output tree.                            |
| `vendor`              | `config.vendor-dir` or `vendor` | Project vendor directory.                                                     |
| `dist`                | `dist`                          | Built asset directory checked or copied by the build.                         |
| `output`              | `build`                         | Staged production tree.                                                       |
| `artifacts`           | `.build`                        | Build metadata: prefix map, installed package list, snapshot, log and verify config. |
| `releases`            | `releases`                      | Release zip directory.                                                        |
| `assets.command`      | `null`                          | String or list of shell commands run before staging.                          |
| `assets.sources`      | `[]`                            | Files and directories whose modification time is compared with `dist`.        |
| `assets.check`        | `warn`                          | `off`, `warn` or `fail`.                                                      |
| `composer.args`       | `[]`                            | Extra arguments appended to `composer install`.                               |
| `verify`              | `{}`                            | Map of package name => static PHP probe file; returns a string.               |
| `release.name`        | composer package name           | Base name of the release zip.                                                 |
| `release.plugin_file` | `null`                          | File whose WordPress `Version` header supplies the shipped version.           |

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

`override` wins over `ignore`; a package-qualified entry wins over an unqualified one; the longest namespace match wins. Duplicate decisions are reported; entries that match nothing are reported with `-v`.

### Release version

By default, the archive uses the top-level `version` from `composer.json`. To read the version from a plugin file instead:

```json
{
    "release": {
        "name": "acme-plugin",
        "plugin_file": "plugin.php"
    }
}
```

Scoper reads only the first 8 KB of `plugin_file`, normalizes CR line endings, and extracts the first non-empty WordPress `Version` header. A file without that header fails the release. The header name is fixed as `Version`; constants and custom header names are not supported.

The archive is named `<name>-<version>.zip`. If neither `plugin_file` nor a composer `version` is available, no version suffix is added.

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

Probes call the scoped code after it has been loaded in a clean process. Map each package to its own PHP file:

```json
{
    "extra": {
        "scoper": {
            "verify": {
                "acme/library": "tools/verify-acme-library.php"
            }
        }
    }
}
```

Probe files are static: no configuration is passed. Hardcode the expected scoped names; when the prefix or policy changes, update the probe.

```php
<?php

declare(strict_types=1);

$class = 'Vendor\\Acme\\Build\\Acme\\Library\\Compiler';

if (!class_exists($class)) {
    throw new RuntimeException("{$class} did not autoload");
}

return 'Compiler class loads';
```

Probes run only for packages present in the staged tree. The file is still checked for existence, a file that returns anything other than a string fails the build, and a probe that reads the removed `$config` variable fails on the undefined variable. Warnings raised by code the probe happens to load do not fail it.

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
