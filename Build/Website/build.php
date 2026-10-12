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
 * Builds the KasperCMS website (published on GitHub Pages together with the
 * Composer repository). Release data is taken from the release table in
 * README.md, so the website never has to be updated by hand.
 *
 * Usage:
 *   php Build/Website/build.php [output directory, default: .cache/pages]
 */
$rootPath = dirname(__DIR__, 2);
$sourcePath = __DIR__;
$outputPath = $argv[1] ?? $rootPath . '/.cache/pages';
if (!str_starts_with($outputPath, '/')) {
    $outputPath = $rootPath . '/' . $outputPath;
}

$releases = [];
$readme = (string)file_get_contents($rootPath . '/README.md');
foreach (explode("\n", $readme) as $line) {
    // | **12.4.45.5** | 2026-10-12 | Based on | Fixes | Notes |
    if (!preg_match('/^\|\s*\**(12\.4\.45\.[1-9]\d*)\**\s*\|\s*(\d{4}-\d{2}-\d{2})\s*\|([^|]*)\|([^|]*)\|([^|]*)\|/', $line, $matches)) {
        continue;
    }
    $releases[] = [
        'version' => $matches[1],
        'date' => $matches[2],
        'fixes' => cleanMarkdown($matches[4]),
        'notes' => cleanMarkdown($matches[5]),
    ];
}
if ($releases === []) {
    fwrite(STDERR, 'No releases found in README.md.' . PHP_EOL);
    exit(2);
}
usort($releases, static fn(array $a, array $b): int => version_compare($b['version'], $a['version']));
$latest = $releases[0];

$items = [];
foreach (array_slice($releases, 0, 5) as $index => $release) {
    // "–" or "– (no new advisories)" means the release contains no fixes
    $hasFixes = $release['fixes'] !== '' && !str_starts_with($release['fixes'], '–');
    $text = $hasFixes ? $release['fixes'] : $release['notes'];
    if ($hasFixes && $release['notes'] !== '' && !str_starts_with(strtolower($release['notes']), 'see ')) {
        $text .= '. ' . $release['notes'];
    }
    $items[] = sprintf(
        '                <li class="release"><div class="release-version">%s%s<small>%s</small></div><div class="release-text">%s</div><a href="%s">Details</a></li>',
        html($release['version']),
        $index === 0 ? '<span class="badge">Latest</span>' : '',
        html($release['date']),
        html(ucfirst(rtrim($text, '. ')) . '.'),
        html(releaseUrl($release['version']))
    );
}

$replacements = [
    '{{LATEST_VERSION}}' => html($latest['version']),
    '{{LATEST_DATE}}' => html($latest['date']),
    '{{LATEST_URL}}' => html(releaseUrl($latest['version'])),
    '{{LATEST_TARBALL}}' => html(sprintf('https://github.com/agilastic/kaspercms/releases/download/v%1$s/kaspercms_src-%1$s.tar.gz', $latest['version'])),
    '{{RELEASE_COUNT}}' => (string)count($releases),
    '{{RELEASES}}' => implode("\n", $items),
    '{{BUILD_ID}}' => substr(sha1((string)file_get_contents($sourcePath . '/assets/style.css')), 0, 8),
];
$html = strtr((string)file_get_contents($sourcePath . '/index.html'), $replacements);
if (preg_match('/\{\{[A-Z_]+\}\}/', $html, $unreplaced)) {
    fwrite(STDERR, sprintf('Unreplaced placeholder %s.' . PHP_EOL, $unreplaced[0]));
    exit(2);
}

if (!is_dir($outputPath . '/assets') && !mkdir($outputPath . '/assets', 0775, true)) {
    fwrite(STDERR, sprintf('Output directory "%s" could not be created.' . PHP_EOL, $outputPath));
    exit(2);
}
file_put_contents($outputPath . '/index.html', $html);
foreach (glob($sourcePath . '/assets/*') as $asset) {
    copy($asset, $outputPath . '/assets/' . basename($asset));
}
printf('Website for KasperCMS %s (%d releases) written to %s' . PHP_EOL, $latest['version'], count($releases), $outputPath);

function cleanMarkdown(string $text): string
{
    $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text) ?? $text;
    $text = preg_replace('/,?\s*see coverage matrix$/i', '', trim($text)) ?? $text;
    return trim(str_replace(['**', '`'], '', $text));
}

function releaseUrl(string $version): string
{
    return 'https://github.com/agilastic/kaspercms/releases/tag/v' . $version;
}

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
