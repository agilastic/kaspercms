#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

if (PHP_SAPI !== 'cli') {
    die('Script must be called from command line.' . chr(10));
}

/**
 * KasperCMS release preparation, used by .github/workflows/release.yml
 *
 * Commands:
 *   info                     JSON: version, previous release, next version, changes
 *   docs --date=YYYY-MM-DD   README release table and coverage matrix in ULTS-PROCESS.md
 *   bump-release             set the release version (Typo3Version, composer.json files)
 *   bump-dev                 set the next -dev version (incl. ext_emconf.php)
 *   notes --checksum=SHA256  print the release notes (Markdown)
 *
 * The coverage matrix of the release is built from an existing
 * "Coverage matrix: <version> (in development)" section, completed with
 * all [SECURITY] and [BUGFIX] commits since the previous release tag that
 * are not listed there yet.
 *
 * Exit codes: 0 = success, 1 = nothing to release, 2 = error
 */
final class ReleasePreparation
{
    private const VERSION_FILE = 'typo3/sysext/core/Classes/Information/Typo3Version.php';
    private const DIST_FILE = 'Build/composer/composer.dist.json';
    private const PROCESS_FILE = 'ULTS-PROCESS.md';
    private const README_FILE = 'README.md';

    private string $rootPath;

    public function __construct()
    {
        $this->rootPath = dirname(__DIR__, 2);
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? '';
        $options = $this->parseOptions(array_slice($argv, 2));
        try {
            return match ($command) {
                'info' => $this->info(),
                'docs' => $this->docs((string)($options['date'] ?? gmdate('Y-m-d'))),
                'bump-release' => $this->bumpRelease(),
                'bump-dev' => $this->bumpDev(),
                'notes' => $this->notes((string)($options['checksum'] ?? '')),
                default => throw new \RuntimeException('Usage: prepareRelease.php info|docs|bump-release|bump-dev|notes'),
            };
        } catch (\RuntimeException $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return 2;
        }
    }

    private function info(): int
    {
        $changes = $this->getChanges();
        // Workflow, tooling and documentation changes alone are no reason for a release
        $productChanges = array_filter($changes['other'], static fn(array $change): bool => $change['product']);
        $hasChanges = $changes['security'] !== [] || $changes['bugfix'] !== [] || $productChanges !== [];
        echo json_encode([
            'version' => $this->getVersion(),
            'previous' => $this->getPreviousVersion(),
            'next' => $this->getNextVersion(),
            'hasChanges' => $hasChanges,
            'security' => count($changes['security']),
            'bugfix' => count($changes['bugfix']),
            'other' => count($productChanges),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return $hasChanges ? 0 : 1;
    }

    private function docs(string $date): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \RuntimeException('Invalid date "' . $date . '".');
        }
        $version = $this->getVersion();
        $previous = $this->getPreviousVersion();
        $changes = $this->getChanges();
        $section = $this->buildCoverageMatrix($version, $previous, $date, $changes);

        $process = $this->read(self::PROCESS_FILE);
        $developmentHeading = '## Coverage matrix: ' . $version . ' (in development)';
        $start = strpos($process, $developmentHeading);
        if ($start !== false) {
            $end = strpos($process, "\n## ", $start + strlen($developmentHeading));
            $process = substr($process, 0, $start) . $section . ($end === false ? '' : substr($process, $end + 1));
        } else {
            $anchor = strpos($process, "\n## Coverage matrix: ");
            if ($anchor === false) {
                throw new \RuntimeException('No coverage matrix found in ' . self::PROCESS_FILE . '.');
            }
            $process = substr($process, 0, $anchor + 1) . $section . substr($process, $anchor + 1);
        }
        $this->write(self::PROCESS_FILE, $process);

        $readme = $this->read(self::README_FILE);
        $readme = preg_replace('/^\| \*\*(12\.4\.45\.\d+)\*\* \|/m', '| $1 |', $readme, 1) ?? $readme;
        $row = sprintf(
            '| **%s** | %s | KasperCMS %s | %s | See [coverage matrix](ULTS-PROCESS.md#coverage-matrix-%s) |',
            $version,
            $date,
            $previous,
            $this->summarize($changes),
            str_replace('.', '', $version)
        );
        $readme = preg_replace('/^(\|---\|---\|---\|---\|---\|\n)/m', '$1' . $row . "\n", $readme, 1, $count) ?? $readme;
        if ($count !== 1) {
            throw new \RuntimeException('Release table not found in ' . self::README_FILE . '.');
        }
        $readme = preg_replace(
            '/The next release in development is \*\*[\d.]+\*\* \(`[\d.]+-dev`\)\./',
            sprintf('The next release in development is **%1$s** (`%1$s-dev`).', $this->getNextVersion()),
            $readme
        ) ?? $readme;
        $this->write(self::README_FILE, $readme);
        return 0;
    }

    private function bumpRelease(): int
    {
        $version = $this->getVersion();
        $this->replaceInFile(self::VERSION_FILE, "VERSION = '" . $version . "-dev';", "VERSION = '" . $version . "';");
        $this->replaceInFile(self::DIST_FILE, '"' . $version . '-dev"', '"' . $version . '"');
        foreach ($this->getTrackedFiles('*composer.json') as $file) {
            $content = $this->read($file);
            $updated = preg_replace('/("typo3\/cms-[a-z0-9-]+": )"12\.4\.\*@dev"/', '$1"' . $version . '"', $content) ?? $content;
            if ($updated !== $content) {
                $this->write($file, $updated);
            }
        }
        return 0;
    }

    private function bumpDev(): int
    {
        $version = $this->getVersion();
        $next = $this->getNextVersion();
        $this->replaceInFile(self::VERSION_FILE, "VERSION = '" . $version . "';", "VERSION = '" . $next . "-dev';");
        $this->replaceInFile(self::DIST_FILE, '"' . $version . '"', '"' . $next . '-dev"');
        foreach ($this->getTrackedFiles('*composer.json') as $file) {
            $content = $this->read($file);
            $updated = preg_replace('/("typo3\/cms-[a-z0-9-]+": )"' . preg_quote($version, '/') . '"/', '$1"12.4.*@dev"', $content) ?? $content;
            if ($updated !== $content) {
                $this->write($file, $updated);
            }
        }
        foreach ($this->getTrackedFiles('*ext_emconf.php') as $file) {
            $content = $this->read($file);
            $updated = str_replace("'" . $version . "'", "'" . $next . "'", $content);
            if ($updated !== $content) {
                $this->write($file, $updated);
            }
        }
        return 0;
    }

    private function notes(string $checksum): int
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $checksum)) {
            throw new \RuntimeException('A SHA-256 checksum is required (--checksum=...).');
        }
        $version = $this->getVersion();
        $previous = $this->getPreviousVersion();
        $process = $this->read(self::PROCESS_FILE);
        $heading = '## Coverage matrix: ' . $version . "\n";
        $start = strpos($process, $heading);
        if ($start === false) {
            throw new \RuntimeException('Coverage matrix of ' . $version . ' not found, run "docs" first.');
        }
        $end = strpos($process, "\n## ", $start + strlen($heading));
        $body = trim(substr($process, $start + strlen($heading), $end === false ? null : $end - $start - strlen($heading)));
        // Relative links of ULTS-PROCESS.md point to the repository on GitHub
        $body = preg_replace('/\]\(#([a-z0-9-]+)\)/', '](https://github.com/agilastic/kaspercms/blob/12.4-ults/ULTS-PROCESS.md#$1)', $body) ?? $body;
        $anchor = 'coverage-matrix-' . str_replace('.', '', $version);
        $notes = <<<MARKDOWN
KasperCMS release based on **KasperCMS {$previous}** (TYPO3 CMS 12.4.45).

Independent community release, see the [trademark notice](https://github.com/agilastic/kaspercms/blob/12.4-ults/README.md#trademark-notice).

## Changes

{$body}

See the [coverage matrix](https://github.com/agilastic/kaspercms/blob/12.4-ults/ULTS-PROCESS.md#{$anchor}).

## Upgrading

Drop-in replacement for KasperCMS 12.4.45.x and TYPO3 12.4.45. No database updates are necessary. Please clear all caches after upgrading.

```
typo3_src -> kaspercms_src-{$version}
typo3     -> typo3_src/typo3
index.php -> typo3_src/index.php
```

Composer installations using the [KasperCMS repository](https://github.com/agilastic/kaspercms/blob/12.4-ults/README.md#installation-with-composer) with `~12.4.45.5` or later receive this release with:

```
composer update "typo3/cms-*" symfony/expression-language
```

When upgrading from 12.4.45.5 or earlier, `symfony/expression-language` has to be updated as well (required since 12.4.45.6, CVE-2026-109449).

## Checksum

```
{$checksum}  kaspercms_src-{$version}.tar.gz
```

MARKDOWN;
        echo $notes;
        return 0;
    }

    /**
     * @param array{security: list<array>, bugfix: list<array>, other: list<array>} $changes
     */
    private function buildCoverageMatrix(string $version, string $previous, string $date, array $changes): string
    {
        $existing = $this->getDevelopmentSection($version);
        $tables = [
            'Security fixes' => ['| Fix on `12.4-ults` | Reference |', '|---|---|'],
            'Bug fixes' => ['| Fix on `12.4-ults` | Reference |', '|---|---|'],
            'Other changes' => ['| Change on `12.4-ults` | Notes |', '|---|---|'],
        ];
        $categories = ['security' => 'Security fixes', 'bugfix' => 'Bug fixes', 'other' => 'Other changes'];
        // "Other changes" are written by hand when they need an explanation;
        // only without such a table are product changes listed automatically.
        $hasManualOtherChanges = str_contains($existing, '### Other changes');
        foreach ($categories as $key => $title) {
            foreach ($changes[$key] as $change) {
                if (stripos($existing, $change['subject']) !== false) {
                    continue;
                }
                if ($key === 'other' && ($hasManualOtherChanges || !$change['product'])) {
                    continue;
                }
                if ($key === 'security' && $change['ids'] !== [] && $this->containsAll($existing, $change['ids'])) {
                    continue;
                }
                $existing = $this->addTableRow($existing, $title, $tables[$title], sprintf('| %s | %s |', $change['subject'], $change['reference']));
            }
        }
        $securityNote = $changes['security'] === []
            ? sprintf('no new advisories affecting 12.4 were published since %s. This release contains no security fixes.', $previous)
            : 'the security fixes of this release are listed below.';
        $checked = wordwrap(sprintf(
            'KasperCMS %1$s, released %2$s. Checked against the GitHub advisories, the TYPO3 security feed, `composer audit` and the TYPO3 git history on %2$s (automated ULTS security check in the release workflow): %3$s',
            $version,
            $date,
            $securityNote
        ), 76);
        $verification = wordwrap('Verification: CGL, PHPStan (PHP 8.1, 8.3 and 8.5), the integrity checks of step 6, unit tests and functional tests (SQLite) on PHP 8.1, 8.3 and 8.5.', 76);
        $intro = '## Coverage matrix: ' . $version . "\n\n" . $checked . "\n\n" . $verification . "\n\n";
        return $intro . ($existing !== '' ? trim($existing) . "\n" : '') . "\n";
    }

    /**
     * @param list<string> $needles
     */
    private function containsAll(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Subsections of an existing "(in development)" section, without its heading and introduction.
     */
    private function getDevelopmentSection(string $version): string
    {
        $process = $this->read(self::PROCESS_FILE);
        $heading = '## Coverage matrix: ' . $version . ' (in development)';
        $start = strpos($process, $heading);
        if ($start === false) {
            return '';
        }
        $end = strpos($process, "\n## ", $start + strlen($heading));
        $section = substr($process, $start, $end === false ? null : $end - $start);
        $firstSubsection = strpos($section, "\n### ");
        return $firstSubsection === false ? '' : substr($section, $firstSubsection + 1);
    }

    /**
     * @param list<string> $tableHeader
     */
    private function addTableRow(string $markdown, string $title, array $tableHeader, string $row): string
    {
        $heading = '### ' . $title;
        $position = strpos($markdown, $heading . "\n");
        if ($position === false) {
            return rtrim($markdown) . ($markdown !== '' ? "\n\n" : '') . $heading . "\n\n" . implode("\n", $tableHeader) . "\n" . $row . "\n";
        }
        $nextHeading = strpos($markdown, "\n### ", $position + strlen($heading));
        $part = substr($markdown, $position, $nextHeading === false ? null : $nextHeading - $position);
        $lines = explode("\n", rtrim($part));
        $lastTableLine = null;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '|')) {
                $lastTableLine = $index;
            }
        }
        if ($lastTableLine === null) {
            $lines[] = '';
            array_push($lines, ...$tableHeader);
            $lines[] = $row;
        } else {
            array_splice($lines, $lastTableLine + 1, 0, [$row]);
        }
        $updated = implode("\n", $lines) . "\n";
        return substr($markdown, 0, $position) . $updated . ($nextHeading === false ? '' : substr($markdown, $nextHeading));
    }

    /**
     * @param array{security: list<array>, bugfix: list<array>, other: list<array>} $changes
     */
    private function summarize(array $changes): string
    {
        $parts = [];
        if ($changes['security'] !== []) {
            $ids = [];
            foreach ($changes['security'] as $change) {
                array_push($ids, ...$change['ids']);
            }
            $ids = array_values(array_unique($ids));
            $parts[] = sprintf(
                '%d security fix%s%s',
                count($changes['security']),
                count($changes['security']) === 1 ? '' : 'es',
                $ids !== [] ? ' (' . implode(', ', array_slice($ids, 0, 3)) . (count($ids) > 3 ? ', …' : '') . ')' : ''
            );
        }
        if ($changes['bugfix'] !== []) {
            $parts[] = sprintf('%d upstream bug fix%s', count($changes['bugfix']), count($changes['bugfix']) === 1 ? '' : 'es');
        }
        return $parts === [] ? '– (no new advisories)' : implode(', ', $parts);
    }

    /**
     * Commits since the previous release tag, grouped for the coverage matrix.
     *
     * @return array{security: list<array>, bugfix: list<array>, other: list<array>}
     */
    private function getChanges(): array
    {
        $previousTag = 'v' . $this->getPreviousVersion();
        $log = $this->exec(sprintf(
            'git log --no-merges --reverse --format=%s %s',
            escapeshellarg('%H%x1f%s%x1f%b%x1e'),
            escapeshellarg($previousTag . '..HEAD')
        ));
        $changes = ['security' => [], 'bugfix' => [], 'other' => []];
        foreach (array_filter(explode("\x1e", $log), 'trim') as $entry) {
            [$sha, $subject, $body] = array_pad(explode("\x1f", trim($entry), 3), 3, '');
            $subject = trim($subject);
            if (preg_match('/^\[(RELEASE|DOCS)\]/', $subject) || str_starts_with($subject, '[TASK] Set TYPO3 version to')) {
                continue;
            }
            $isCherryPick = (bool)preg_match('/cherry picked from commit [0-9a-f]{40}/', $body);
            preg_match_all('/^Resolves: #(\d+)/m', $body, $resolves);
            preg_match_all('/\b(CVE-\d{4}-\d{4,}|GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4})\b/i', $body, $advisories);
            preg_match('/^Security-Bulletin: (TYPO3-CORE-SA-\d{4}-\d{3})/m', $body, $bulletin);
            $ids = array_values(array_unique(array_map('strtoupper', $advisories[1])));
            $references = [];
            if ($ids !== []) {
                $references[] = implode(', ', $ids);
            }
            if (($bulletin[1] ?? '') !== '') {
                $references[] = $bulletin[1];
            }
            foreach ($resolves[1] as $issue) {
                $references[] = 'Forge #' . $issue;
            }
            $title = trim((string)preg_replace('/^\[[A-Z!]+\](\[[A-Z!]+\])*\s*/', '', $subject));
            $files = array_filter(explode("\n", $this->exec('git show --name-only --format= ' . escapeshellarg(trim($sha)))));
            $change = [
                'subject' => $title,
                // Changes of the shipped code, not of workflows or build tooling
                'product' => array_filter($files, static fn(string $file): bool => str_starts_with($file, 'typo3/') || in_array($file, ['composer.json', 'composer.lock', 'index.php'], true)) !== [],
                'ids' => $ids,
                'reference' => $references !== [] ? implode(', ', $references) : ($isCherryPick ? 'Upstream backport' : 'KasperCMS'),
            ];
            if (str_starts_with($subject, '[SECURITY]')) {
                $changes['security'][] = $change;
            } elseif (str_starts_with($subject, '[BUGFIX]') && $isCherryPick) {
                $changes['bugfix'][] = $change;
            } else {
                $changes['other'][] = $change;
            }
        }
        return $changes;
    }

    private function getVersion(): string
    {
        if (!preg_match("/VERSION = '(12\\.4\\.45\\.\\d+)(-dev)?';/", $this->read(self::VERSION_FILE), $match)) {
            throw new \RuntimeException('Version not found in ' . self::VERSION_FILE . '.');
        }
        return $match[1];
    }

    private function getNextVersion(): string
    {
        $parts = explode('.', $this->getVersion());
        $parts[3] = (string)((int)$parts[3] + 1);
        return implode('.', $parts);
    }

    private function getPreviousVersion(): string
    {
        $tags = array_filter(explode("\n", $this->exec('git tag --list ' . escapeshellarg('v12.4.45.*'))));
        $versions = array_values(array_filter(
            array_map(static fn(string $tag): string => ltrim(trim($tag), 'v'), $tags),
            fn(string $version): bool => preg_match('/^12\.4\.45\.[1-9]\d*$/', $version) === 1 && version_compare($version, $this->getVersion(), '<')
        ));
        if ($versions === []) {
            throw new \RuntimeException('No previous release tag found.');
        }
        usort($versions, 'version_compare');
        return end($versions);
    }

    /**
     * @return list<string>
     */
    private function getTrackedFiles(string $pattern): array
    {
        return array_values(array_filter(explode("\n", $this->exec('git ls-files ' . escapeshellarg($pattern)))));
    }

    private function replaceInFile(string $file, string $search, string $replace): void
    {
        $content = $this->read($file);
        if (!str_contains($content, $search)) {
            throw new \RuntimeException(sprintf('"%s" not found in %s.', $search, $file));
        }
        $this->write($file, str_replace($search, $replace, $content));
    }

    private function read(string $file): string
    {
        $content = @file_get_contents($this->rootPath . '/' . $file);
        if ($content === false) {
            throw new \RuntimeException('Could not read ' . $file . '.');
        }
        return $content;
    }

    private function write(string $file, string $content): void
    {
        if (file_put_contents($this->rootPath . '/' . $file, $content) === false) {
            throw new \RuntimeException('Could not write ' . $file . '.');
        }
    }

    private function exec(string $command): string
    {
        $output = [];
        exec('cd ' . escapeshellarg($this->rootPath) . ' && ' . $command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Command failed: ' . $command);
        }
        return implode("\n", $output);
    }

    private function parseOptions(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--')) {
                [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
                $options[$name] = $value;
            }
        }
        return $options;
    }
}

exit((new ReleasePreparation())->run($argv));
