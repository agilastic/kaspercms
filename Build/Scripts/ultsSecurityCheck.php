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
 * KasperCMS ULTS security check
 *
 * Compares public security information with the current branch and prints a
 * Markdown report:
 *
 * - advisories:   GitHub security advisories of TYPO3/typo3 and
 *                 TYPO3/html-sanitizer affecting the base version
 * - upstream:     [SECURITY] commits on the upstream TYPO3 branches without a
 *                 counterpart on the current branch
 * - dependencies: `composer audit` and `npm audit` (CKEditor packages)
 * - php:          security support of the PHP versions supported by TYPO3 12.4
 * - rss:          TYPO3 core security bulletins (TYPO3-CORE-SA) of the TYPO3
 *                 security RSS feed
 * - tests:        unit tests, PHPStan and CGL via runTests.sh (--with-tests)
 * - bugfixes:     [BUGFIX] commits on the upstream bug fix branch (13.4) without
 *                 a counterpart on the current branch (not part of the default
 *                 checks, use --checks=bugfixes)
 *
 * An item counts as handled when its CVE, GHSA or TYPO3-CORE-SA identifier is documented in
 * ULTS-PROCESS.md (coverage matrix or "not affected" table). An upstream bug
 * fix counts as handled when it was cherry-picked or its commit hash (11
 * characters) is documented in ULTS-PROCESS.md.
 *
 * Exit codes: 0 = no open items, 1 = open items, 2 = error
 *
 * Usage:
 *   php Build/Scripts/ultsSecurityCheck.php [options]
 *
 *   --base-version=12.4.45     upstream version KasperCMS is based on
 *   --base-ref=v12.4.45        git ref of that version
 *   --since=2026-04-14         ignore advisories and commits before this date
 *   --upstream-remote=upstream git remote of TYPO3/typo3
 *   --upstream-branches=13.4,14.3,main
 *   --checks=advisories,upstream,dependencies,php,rss
 *   --php-versions=8.1,8.2,8.3,8.4,8.5
 *   --bugfix-branch=13.4       upstream branch checked for bug fixes
 *   --fetch                    fetch the upstream branches first
 *   --with-tests               additionally run unit tests, PHPStan and CGL
 */
final class UltsSecurityCheck
{
    private const DEFAULT_OPTIONS = [
        'base-version' => '12.4.45',
        'base-ref' => 'v12.4.45',
        'since' => '2026-04-14',
        'upstream-remote' => 'upstream',
        'upstream-branches' => '13.4,14.3,main',
        'checks' => 'advisories,upstream,dependencies,php,rss',
        'php-versions' => '8.1,8.2,8.3,8.4,8.5',
        'bugfix-branch' => '13.4',
    ];

    private string $rootPath;
    private array $options;
    /** @var array<string, true> */
    private array $documentedIds = [];
    /** @var array<string, true> */
    private array $documentedCommits = [];
    /** @var list<string> */
    private array $report = [];
    private int $openItems = 0;
    private int $errors = 0;

    public function __construct(array $argv)
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->options = $this->parseOptions($argv);
    }

    public function run(): int
    {
        $this->loadDocumentedIds();
        $this->report[] = '# KasperCMS ULTS security check';
        $this->report[] = '';
        $this->report[] = sprintf(
            'Date: %s | Branch HEAD: `%s` | Base: TYPO3 %s (`%s`) | Since: %s',
            gmdate('Y-m-d H:i') . ' UTC',
            trim($this->exec('git rev-parse --short HEAD')),
            $this->options['base-version'],
            $this->options['base-ref'],
            $this->options['since']
        );
        $this->report[] = '';
        $this->report[] = sprintf(
            '%d CVE/GHSA/TYPO3-CORE-SA identifiers are documented in ULTS-PROCESS.md and count as handled.',
            count($this->documentedIds)
        );

        $checks = array_map('trim', explode(',', $this->options['checks']));
        if (in_array('advisories', $checks, true)) {
            $this->checkAdvisories();
        }
        if (in_array('upstream', $checks, true)) {
            $this->checkUpstreamCommits();
        }
        if (in_array('dependencies', $checks, true)) {
            $this->checkDependencies();
        }
        if (in_array('rss', $checks, true)) {
            $this->checkSecurityFeed();
        }
        if (in_array('php', $checks, true)) {
            $this->checkPhpVersions();
        }
        if (in_array('bugfixes', $checks, true)) {
            $this->checkUpstreamBugfixes();
        }
        if (isset($this->options['with-tests'])) {
            $this->runTests();
        }

        $this->report[] = '';
        $this->report[] = '## Summary';
        $this->report[] = '';
        if ($this->errors > 0) {
            $this->report[] = sprintf('**%d check(s) could not be completed.** See the messages above.', $this->errors);
        }
        $this->report[] = $this->openItems > 0
            ? sprintf('**%d open item(s)** need triage (see ULTS-PROCESS.md).', $this->openItems)
            : 'No open items.';

        echo implode(PHP_EOL, $this->report) . PHP_EOL;
        if ($this->errors > 0) {
            return 2;
        }
        return $this->openItems > 0 ? 1 : 0;
    }

    private function checkAdvisories(): void
    {
        $this->section('Security advisories (GitHub)');
        $rows = [];
        $checked = 0;
        foreach (['TYPO3/typo3', 'TYPO3/html-sanitizer'] as $repository) {
            $advisories = $this->ghApi('repos/' . $repository . '/security-advisories?per_page=100');
            if ($advisories === null) {
                continue;
            }
            foreach ($advisories as $advisory) {
                if (($advisory['published_at'] ?? '') < $this->options['since']) {
                    continue;
                }
                $checked++;
                $ids = array_filter([$advisory['cve_id'] ?? null, $advisory['ghsa_id'] ?? null]);
                if ($this->isDocumented($ids)) {
                    continue;
                }
                $affected = $repository === 'TYPO3/typo3'
                    ? $this->coreAdvisoryAffectsBase($advisory)
                    : $this->packageAdvisoryAffectsLock($advisory, 'typo3/html-sanitizer');
                if ($affected === false) {
                    // not affecting 12.4 and not documented: only informational
                    $rows[] = [implode(' / ', $ids), $advisory['severity'] ?? '', $advisory['summary'] ?? '', 'not affected, document in ULTS-PROCESS.md'];
                    continue;
                }
                $rows[] = [implode(' / ', $ids), $advisory['severity'] ?? '', $advisory['summary'] ?? '', '**OPEN**'];
                $this->openItems++;
            }
        }
        $this->report[] = sprintf('%d advisories published since %s checked.', $checked, $this->options['since']);
        $this->report[] = '';
        $this->table(['Advisory', 'Severity', 'Summary', 'Status'], $rows, 'No undocumented advisories.');
    }

    private function coreAdvisoryAffectsBase(array $advisory): bool
    {
        foreach ($advisory['vulnerabilities'] ?? [] as $vulnerability) {
            if (!str_starts_with((string)($vulnerability['package']['name'] ?? ''), 'typo3/cms-')) {
                continue;
            }
            if ($this->versionInRanges($this->options['base-version'], (string)($vulnerability['vulnerable_version_range'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    private function packageAdvisoryAffectsLock(array $advisory, string $packageName): bool
    {
        $lockedVersion = $this->lockedComposerVersion($packageName);
        foreach ($advisory['vulnerabilities'] ?? [] as $vulnerability) {
            if (($vulnerability['package']['name'] ?? '') !== $packageName) {
                continue;
            }
            if ($lockedVersion === null || $this->versionInRanges($lockedVersion, (string)($vulnerability['vulnerable_version_range'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    private function checkUpstreamCommits(): void
    {
        $this->section('Upstream [SECURITY] commits');
        $remote = $this->options['upstream-remote'];
        $branches = array_map('trim', explode(',', $this->options['upstream-branches']));
        if (isset($this->options['fetch'])) {
            $this->exec(sprintf('git fetch --quiet %s %s 2>&1', escapeshellarg($remote), implode(' ', array_map('escapeshellarg', $branches))), $exitCode);
            if ($exitCode !== 0) {
                $this->error(sprintf('Could not fetch from remote "%s".', $remote));
                return;
            }
        }

        $ownCommits = $this->loadOwnCommits();
        if ($ownCommits === null) {
            return;
        }
        [$ownSubjects, $ownCherryPicks] = $ownCommits;

        $seenSubjects = [];
        $rows = [];
        $checked = 0;
        $errorsBefore = $this->errors;
        foreach ($branches as $branch) {
            $ref = $remote . '/' . $branch;
            $log = $this->exec(sprintf(
                'git log %s --since=%s --grep=%s --format=%s',
                escapeshellarg($ref),
                escapeshellarg($this->options['since']),
                escapeshellarg('^\[SECURITY\]'),
                escapeshellarg('%H%x1f%s%x1f%(trailers:key=Security-References,valueonly,separator=%x2C)%x1e')
            ), $exitCode);
            if ($exitCode !== 0) {
                $this->error(sprintf('Upstream ref "%s" not found. Add the remote and use --fetch.', $ref));
                continue;
            }
            foreach (array_filter(explode("\x1e", $log), 'trim') as $entry) {
                [$sha, $subject, $references] = array_pad(explode("\x1f", trim($entry), 3), 3, '');
                $subject = trim($subject);
                if (isset($seenSubjects[$subject])) {
                    continue;
                }
                $seenSubjects[$subject] = true;
                $checked++;
                if (isset($ownSubjects[$subject]) || isset($ownCherryPicks[$sha])) {
                    continue;
                }
                $ids = array_filter(array_map('trim', explode(',', $references)));
                if ($ids !== [] && $this->isDocumented($ids)) {
                    continue;
                }
                $rows[] = [substr($sha, 0, 11), $branch, $subject, $ids !== [] ? implode(', ', $ids) : '–', '**OPEN**'];
                $this->openItems++;
            }
        }
        $this->report[] = sprintf('%d distinct upstream security commits since %s checked.', $checked, $this->options['since']);
        $this->report[] = '';
        $this->table(
            ['Commit', 'Branch', 'Subject', 'References', 'Status'],
            $rows,
            $this->errors === $errorsBefore ? 'All upstream security commits are covered or documented.' : ''
        );
    }

    /**
     * Lists upstream bug fixes that are neither backported nor documented as
     * not backported. Whether a fix is backportable is decided manually, see
     * ULTS-PROCESS.md, "Bug fix backports".
     */
    private function checkUpstreamBugfixes(): void
    {
        $branch = $this->options['bugfix-branch'];
        $this->section(sprintf('Upstream [BUGFIX] commits (%s)', $branch));
        $ref = $this->options['upstream-remote'] . '/' . $branch;
        if (isset($this->options['fetch'])) {
            $this->exec(sprintf('git fetch --quiet %s %s 2>&1', escapeshellarg($this->options['upstream-remote']), escapeshellarg($branch)), $exitCode);
            if ($exitCode !== 0) {
                $this->error(sprintf('Could not fetch from remote "%s".', $this->options['upstream-remote']));
                return;
            }
        }
        $ownCommits = $this->loadOwnCommits();
        if ($ownCommits === null) {
            return;
        }
        [$ownSubjects, $ownCherryPicks] = $ownCommits;

        $log = $this->exec(sprintf(
            'git log %s --since=%s --reverse --grep=%s --format=%s',
            escapeshellarg($ref),
            escapeshellarg($this->options['since']),
            escapeshellarg('^\[BUGFIX\]'),
            escapeshellarg('%H%x1f%ad%x1f%s%x1f%(trailers:key=Resolves,valueonly,separator=%x2C)%x1e') . ' --date=short'
        ), $exitCode);
        if ($exitCode !== 0) {
            $this->error(sprintf('Upstream ref "%s" not found. Add the remote and use --fetch.', $ref));
            return;
        }
        $rows = [];
        $checked = 0;
        $backported = 0;
        $documented = 0;
        foreach (array_filter(explode("\x1e", $log), 'trim') as $entry) {
            [$sha, $date, $subject, $resolves] = array_pad(explode("\x1f", trim($entry), 4), 4, '');
            $subject = trim($subject);
            $checked++;
            if (isset($ownSubjects[$subject]) || isset($ownCherryPicks[$sha])) {
                $backported++;
                continue;
            }
            if (isset($this->documentedCommits[substr($sha, 0, 11)])) {
                $documented++;
                continue;
            }
            $this->exec(sprintf('git show --format= %s | git apply --check 2>/dev/null', escapeshellarg($sha)), $applyExitCode);
            $rows[] = [
                substr($sha, 0, 11),
                $date,
                $subject,
                trim($resolves) !== '' ? trim($resolves) : '–',
                $applyExitCode === 0 ? 'yes' : 'conflicts',
            ];
            $this->openItems++;
        }
        $this->report[] = sprintf(
            '%d upstream bug fixes since %s checked: %d backported, %d documented as not backported, %d to triage.',
            $checked,
            $this->options['since'],
            $backported,
            $documented,
            count($rows)
        );
        $this->report[] = '';
        $this->table(
            ['Commit', 'Date', 'Subject', 'Resolves', 'Applies cleanly'],
            $rows,
            'All upstream bug fixes are backported or documented.'
        );
    }

    /**
     * Subjects and cherry-picked upstream commits of the current branch since the base ref.
     *
     * @return array{0: array<string, true>, 1: array<string, true>}|null
     */
    private function loadOwnCommits(): ?array
    {
        $ownSubjects = [];
        $ownCherryPicks = [];
        $ownLog = $this->exec(sprintf('git log %s..HEAD --format=%s', escapeshellarg($this->options['base-ref']), escapeshellarg('%s%x1f%b%x1e')), $exitCode);
        if ($exitCode !== 0) {
            $this->error(sprintf('Base ref "%s" not found.', $this->options['base-ref']));
            return null;
        }
        foreach (array_filter(explode("\x1e", $ownLog), 'trim') as $entry) {
            [$subject, $body] = array_pad(explode("\x1f", trim($entry), 2), 2, '');
            $ownSubjects[trim($subject)] = true;
            if (preg_match_all('/cherry picked from commit ([0-9a-f]{40})/', $body, $matches)) {
                foreach ($matches[1] as $sha) {
                    $ownCherryPicks[$sha] = true;
                }
            }
        }
        return [$ownSubjects, $ownCherryPicks];
    }

    private function checkDependencies(): void
    {
        $this->section('Dependencies');

        $rows = [];
        $production = $this->composerAudit(true);
        $all = $this->composerAudit(false);
        if ($production === null || $all === null) {
            $this->error('`composer audit` did not return JSON (is composer installed?).');
        } else {
            foreach ($all as $key => [$package, $ids, $title]) {
                if ($this->isDocumented($ids)) {
                    continue;
                }
                if (isset($production[$key])) {
                    $rows[] = ['composer', $package, implode(' / ', $ids), $title, '**OPEN**'];
                    $this->openItems++;
                } else {
                    // require-dev only, not part of release packages
                    $rows[] = ['composer (dev)', $package, implode(' / ', $ids), $title, 'dev only'];
                }
            }
        }

        $output = $this->exec('npm audit --package-lock-only --json --prefix ' . escapeshellarg($this->rootPath . '/Build') . ' 2>/dev/null', $exitCode);
        $audit = json_decode($output, true);
        if (!is_array($audit)) {
            $this->error('`npm audit` did not return JSON (is npm installed?).');
        } else {
            $seen = [];
            $ckeditorAdvisories = [];
            foreach ($audit['vulnerabilities'] ?? [] as $package => $vulnerability) {
                if (!str_starts_with($package, '@ckeditor/') && $package !== 'ckeditor5') {
                    continue;
                }
                foreach ($vulnerability['via'] ?? [] as $via) {
                    if (!is_array($via)) {
                        continue;
                    }
                    $ghsa = preg_match('/GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}/', (string)($via['url'] ?? ''), $match) ? $match[0] : '';
                    if ($ghsa !== '') {
                        $ckeditorAdvisories[$ghsa] = true;
                    }
                    if ($ghsa === '' || isset($seen[$ghsa]) || $this->isDocumented([$ghsa])) {
                        continue;
                    }
                    $seen[$ghsa] = true;
                    $rows[] = ['npm', (string)($via['name'] ?? $package), $ghsa, (string)($via['title'] ?? ''), '**OPEN**'];
                    $this->openItems++;
                }
            }
        }
        if (isset($ckeditorAdvisories)) {
            $this->report[] = sprintf('%d CKEditor advisories reported by npm audit (handled ones are documented in ULTS-PROCESS.md).', count($ckeditorAdvisories));
            $this->report[] = '';
        }
        $this->table(['Manager', 'Package', 'Advisory', 'Title', 'Status'], $rows, 'No undocumented advisories for locked dependencies.');
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string}>|null
     */
    private function composerAudit(bool $productionOnly): ?array
    {
        $output = $this->exec(sprintf(
            'composer audit --locked --format=json --no-interaction --working-dir=%s%s 2>/dev/null',
            escapeshellarg($this->rootPath),
            $productionOnly ? ' --no-dev' : ''
        ));
        $audit = json_decode($output, true);
        if (!is_array($audit)) {
            return null;
        }
        $result = [];
        foreach ($audit['advisories'] ?? [] as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $ids = [];
                $candidates = array_merge(
                    [$advisory['cve'] ?? null],
                    array_map(static fn(array $source): ?string => $source['remoteId'] ?? null, $advisory['sources'] ?? [])
                );
                foreach ($candidates as $candidate) {
                    if (is_string($candidate) && preg_match('/^(CVE-\d{4}-\d+|GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4})$/i', $candidate)) {
                        $ids[] = $candidate;
                    }
                }
                $ids = array_values(array_unique($ids));
                if ($ids === []) {
                    $ids = [(string)($advisory['advisoryId'] ?? '')];
                }
                $result[$package . '|' . $ids[0]] = [(string)$package, $ids, (string)($advisory['title'] ?? '')];
            }
        }
        return $result;
    }

    /**
     * The feed does not contain affected versions, so every undocumented core
     * bulletin is an open item that needs triage.
     */
    private function checkSecurityFeed(): void
    {
        $this->section('TYPO3 security feed (typo3.org/rss-security)');
        $xml = @simplexml_load_string($this->exec('curl -fsSL https://typo3.org/rss-security 2>/dev/null'), \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            $this->error('The TYPO3 security feed could not be fetched or parsed.');
            return;
        }
        $rows = [];
        $checked = 0;
        foreach ($xml->channel->item ?? [] as $item) {
            $title = trim((string)$item->title);
            if (!preg_match('/^(TYPO3-CORE-SA-\d{4}-\d{3})/', $title, $match)) {
                continue;
            }
            $published = gmdate('Y-m-d', (int)strtotime((string)$item->pubDate));
            if ($published < $this->options['since']) {
                continue;
            }
            $checked++;
            if ($this->isDocumented([$match[1]])) {
                continue;
            }
            $rows[] = [$published, $title, (string)$item->link, '**OPEN**'];
            $this->openItems++;
        }
        $this->report[] = sprintf('%d core bulletins published since %s checked.', $checked, $this->options['since']);
        $this->report[] = '';
        $this->table(['Published', 'Bulletin', 'Link', 'Status'], $rows, 'All core bulletins are documented.');
    }

    /**
     * Only an upcoming end of security support of the newest supported PHP
     * version is an open item: KasperCMS then needs to support a newer one.
     */
    private function checkPhpVersions(): void
    {
        $this->section('PHP versions');
        $data = json_decode($this->exec('curl -fsSL https://endoflife.date/api/php.json 2>/dev/null'), true);
        if (!is_array($data)) {
            $this->error('PHP support dates could not be fetched from endoflife.date.');
            return;
        }
        $endOfLife = [];
        foreach ($data as $cycle) {
            $endOfLife[(string)($cycle['cycle'] ?? '')] = (string)($cycle['eol'] ?? '');
        }
        $today = gmdate('Y-m-d');
        $inSixMonths = gmdate('Y-m-d', strtotime('+6 months'));
        $inTwelveMonths = gmdate('Y-m-d', strtotime('+12 months'));
        $versions = array_map('trim', explode(',', $this->options['php-versions']));
        $newest = end($versions);
        $rows = [];
        foreach ($versions as $version) {
            $eol = $endOfLife[$version] ?? '';
            if ($eol === '') {
                $status = 'unknown';
            } elseif ($eol < $today) {
                $status = 'end of life';
            } elseif ($eol < $inSixMonths) {
                $status = 'ends within 6 months';
            } else {
                $status = 'supported';
            }
            if ($version === $newest && $eol !== '' && $eol < $inTwelveMonths) {
                $status = '**OPEN**: newest supported PHP version, add support for a newer one';
                $this->openItems++;
            }
            $rows[] = [$version, $eol ?: '–', $status];
        }
        $this->table(['PHP', 'Security fixes until', 'Status'], $rows, '');
    }

    private function runTests(): void
    {
        $this->section('Tests');
        $runtime = $this->commandExists('podman') ? 'podman' : 'docker';
        $suites = [
            'unit' => '-s unit',
            'phpstan' => '-s phpstan',
            'cgl' => '-s cgl -n',
        ];
        $rows = [];
        foreach ($suites as $name => $arguments) {
            $this->exec(sprintf(
                'cd %s && CI=true ./Build/Scripts/runTests.sh -b %s -p 8.1 %s > /dev/null 2>&1',
                escapeshellarg($this->rootPath),
                $runtime,
                $arguments
            ), $exitCode);
            $rows[] = [$name, $exitCode === 0 ? 'passed' : '**FAILED**'];
            if ($exitCode !== 0) {
                $this->openItems++;
            }
        }
        $this->table(['Suite', 'Result'], $rows, '');
        $this->report[] = '';
        $this->report[] = 'Functional tests are not part of this check, see ULTS-PROCESS.md, step 6.';
    }

    private function loadDocumentedIds(): void
    {
        $file = $this->rootPath . '/ULTS-PROCESS.md';
        $content = is_file($file) ? (string)file_get_contents($file) : '';
        if (preg_match_all('/\b(CVE-\d{4}-\d{4,}|GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}|TYPO3-CORE-SA-\d{4}-\d{3})\b/i', $content, $matches)) {
            foreach ($matches[1] as $id) {
                $this->documentedIds[strtoupper($id)] = true;
            }
        }
        if (preg_match_all('/`([0-9a-f]{11})`/', $content, $matches)) {
            foreach ($matches[1] as $sha) {
                $this->documentedCommits[$sha] = true;
            }
        }
    }

    /**
     * @param array<int, string|null> $ids
     */
    private function isDocumented(array $ids): bool
    {
        foreach ($ids as $id) {
            if ($id !== null && $id !== '' && isset($this->documentedIds[strtoupper($id)])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Supports ranges like "12.0.0-12.4.48, 13.0.0-13.4.34", "<10.4.57",
     * "<= 1.2.3" and ">= 1.0.0, < 2.0.0".
     */
    private function versionInRanges(string $version, string $ranges): bool
    {
        $lowerBound = null;
        foreach (array_map('trim', explode(',', $ranges)) as $range) {
            if ($range === '') {
                continue;
            }
            if (preg_match('/^v?(\d[\w.]*)\s*-\s*v?(\d[\w.]*)$/', $range, $match)) {
                if (version_compare($version, $match[1], '>=') && version_compare($version, $match[2], '<=')) {
                    return true;
                }
                continue;
            }
            if (preg_match('/^(<=|<|>=|>|=)\s*v?(\d[\w.]*)$/', $range, $match)) {
                if ($match[1] === '>=' || $match[1] === '>') {
                    $lowerBound = $match;
                    continue;
                }
                $matchesUpper = version_compare($version, $match[2], $match[1] === '=' ? '==' : $match[1]);
                $matchesLower = $lowerBound === null || version_compare($version, $lowerBound[2], $lowerBound[1]);
                $lowerBound = null;
                if ($matchesUpper && $matchesLower) {
                    return true;
                }
                continue;
            }
            if (preg_match('/^v?(\d[\w.]*)$/', $range, $match) && version_compare($version, $match[1], '==')) {
                return true;
            }
        }
        return $lowerBound !== null && version_compare($version, $lowerBound[2], $lowerBound[1]);
    }

    private function lockedComposerVersion(string $packageName): ?string
    {
        $lock = json_decode((string)@file_get_contents($this->rootPath . '/composer.lock'), true);
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (($package['name'] ?? '') === $packageName) {
                return ltrim((string)$package['version'], 'v');
            }
        }
        return null;
    }

    private function ghApi(string $endpoint): ?array
    {
        $output = $this->exec('gh api --paginate ' . escapeshellarg($endpoint) . ' 2>/dev/null', $exitCode);
        // --paginate concatenates JSON arrays of all pages
        $data = json_decode((string)preg_replace('/\]\s*\[/', ',', trim($output)), true);
        if ($exitCode !== 0 || !is_array($data)) {
            $this->error(sprintf('GitHub API request "%s" failed (is gh installed and authenticated?).', $endpoint));
            return null;
        }
        return $data;
    }

    private function exec(string $command, ?int &$exitCode = null): string
    {
        $output = [];
        exec('cd ' . escapeshellarg($this->rootPath) . ' && ' . $command, $output, $exitCode);
        return implode("\n", $output);
    }

    private function commandExists(string $command): bool
    {
        $this->exec('command -v ' . escapeshellarg($command) . ' > /dev/null 2>&1', $exitCode);
        return $exitCode === 0;
    }

    private function section(string $title): void
    {
        $this->report[] = '';
        $this->report[] = '## ' . $title;
        $this->report[] = '';
    }

    private function table(array $header, array $rows, string $emptyMessage): void
    {
        if ($rows === []) {
            if ($emptyMessage !== '') {
                $this->report[] = $emptyMessage;
            }
            return;
        }
        $this->report[] = '| ' . implode(' | ', $header) . ' |';
        $this->report[] = '|' . str_repeat('---|', count($header));
        foreach ($rows as $row) {
            $this->report[] = '| ' . implode(' | ', array_map(static fn($cell): string => str_replace('|', '\\|', (string)$cell), $row)) . ' |';
        }
    }

    private function error(string $message): void
    {
        $this->errors++;
        $this->report[] = '> **Error:** ' . $message;
    }

    private function parseOptions(array $argv): array
    {
        $options = self::DEFAULT_OPTIONS;
        foreach (array_slice($argv, 1) as $argument) {
            if (!str_starts_with($argument, '--')) {
                continue;
            }
            [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, true);
            $options[$name] = $value;
        }
        return $options;
    }
}

exit((new UltsSecurityCheck($argv))->run());
