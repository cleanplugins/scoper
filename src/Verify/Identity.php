<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Verify;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Config\Glob;
use CleanPlugins\Scoper\Exception\ScoperException;

/**
 * An ignored package keeps its own declarations; only its references to *scoped* packages may be
 * rewritten.
 */
final class Identity
{
    public function __construct(private readonly Config $config) {}

    /** @param array<string, mixed> $map */
    public function assertIgnoredPackages(array $map): int
    {
        $scoped = (string) ($map['prefix'] ?? '') . '\\';
        $excluded = $map['excluded']['paths'] ?? [];
        $checked = 0;

        foreach ($map['edits'] ?? [] as $file => $edits) {
            if (!str_starts_with((string) $file, $this->config->vendor . '/') || !Glob::isIgnored((string) $file, $excluded)) {
                continue;
            }

            foreach ($edits as $edit) {
                ++$checked;

                if (str_starts_with((string) $edit['to'], $scoped) && !str_starts_with((string) $edit['from'], $scoped)) {
                    continue;
                }

                throw new ScoperException("{$file} is inside an ignored package but was rewritten in a way that is not a scoped reference", ["{$edit['from']} → {$edit['to']}"]);
            }
        }

        return $checked;
    }
}
