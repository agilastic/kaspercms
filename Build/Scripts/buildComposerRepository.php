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
 * KasperCMS Composer repository builder
 *
 * Builds a static Composer repository for the typo3/cms-* packages of all
 * KasperCMS releases:
 *
 * - one zip archive per release tag and system extension, created with
 *   `git archive` from the tag (reproducible: fixed mtime of the tag commit),
 * - a `packages.json` that lists every version with its dist URL and SHA-1.
 *
 * The archives are meant to be attached to the GitHub release of the tag, the
 * `packages.json` is published on a static web server (GitHub Pages).
 *
 * Usage:
 *   php Build/Scripts/buildComposerRepository.php [options]
 *
 *   --output=.cache/composer-repository  output directory
 *   --tags=v12.4.45.4,v12.4.45.5          tags to include (default: all v12.4.45.* tags with a number > 0)
 *   --dist-url=https://github.com/agilastic/kaspercms/releases/download/{tag}/{file}
 *                                        URL template of the archives; {tag}, {version} and {file} are replaced
 *   --skip-archives                      only write packages.json (archives must already exist in --output)
 *
 * Exit codes: 0 = success, 2 = error
 */
final class ComposerRepositoryBuilder
{
    private const DEFAULT_OPTIONS = [
        'output' => '.cache/composer-repository',
        'dist-url' => 'https://github.com/agilastic/kaspercms/releases/download/{tag}/{file}',
    ];

    private string $rootPath;
    private array $options;

    public function __construct(array $argv)
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->options = $this->parseOptions($argv);
    }

    public function run(): int
    {
        $output = $this->absolutePath($this->options['output']);
        if (!is_dir($output) && !mkdir($output, 0775, true)) {
            fwrite(STDERR, sprintf('Output directory "%s" could not be created.' . PHP_EOL, $output));
            return 2;
        }

        $tags = $this->getTags();
        if ($tags === []) {
            fwrite(STDERR, 'No release tags found.' . PHP_EOL);
            return 2;
        }

        $packages = [];
        foreach ($tags as $tag) {
            $version = ltrim($tag, 'v');
            $time = trim($this->exec(sprintf('git log -1 --format=%%cI %s', escapeshellarg($tag))));
            foreach ($this->getSystemExtensions($tag) as $extensionKey) {
                $manifest = json_decode($this->exec(sprintf('git show %s', escapeshellarg($tag . ':typo3/sysext/' . $extensionKey . '/composer.json'))), true);
                if (!is_array($manifest) || !isset($manifest['name'])) {
                    fwrite(STDERR, sprintf('Invalid composer.json of "%s" in %s.' . PHP_EOL, $extensionKey, $tag));
                    return 2;
                }
                $name = $manifest['name'];
                $file = str_replace('/', '-', $name) . '-' . $version . '.zip';
                $archive = $output . '/' . $file;
                if (!isset($this->options['skip-archives'])) {
                    $this->exec(sprintf(
                        'git archive --format=zip --mtime=%s -o %s %s',
                        escapeshellarg($time),
                        escapeshellarg($archive),
                        escapeshellarg($tag . ':typo3/sysext/' . $extensionKey)
                    ), $exitCode);
                    if ($exitCode !== 0) {
                        fwrite(STDERR, sprintf('Archive of "%s" in %s could not be created.' . PHP_EOL, $extensionKey, $tag));
                        return 2;
                    }
                }
                if (!is_file($archive)) {
                    fwrite(STDERR, sprintf('Archive "%s" is missing.' . PHP_EOL, $archive));
                    return 2;
                }
                unset($manifest['require-dev'], $manifest['autoload-dev'], $manifest['config']);
                $manifest['version'] = $version;
                $manifest['time'] = $time;
                $manifest['dist'] = [
                    'type' => 'zip',
                    'url' => str_replace(['{tag}', '{version}', '{file}'], [$tag, $version, $file], $this->options['dist-url']),
                    'shasum' => sha1_file($archive),
                ];
                $packages[$name][$version] = $manifest;
            }
        }

        ksort($packages);
        $json = json_encode(['packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($output . '/packages.json', $json . PHP_EOL);

        printf(
            'Composer repository with %d packages in %d version(s) written to %s' . PHP_EOL,
            count($packages),
            count($tags),
            $output
        );
        return 0;
    }

    /**
     * @return list<string>
     */
    private function getTags(): array
    {
        if (isset($this->options['tags']) && is_string($this->options['tags'])) {
            return array_values(array_filter(array_map('trim', explode(',', $this->options['tags']))));
        }
        $tags = array_filter(explode("\n", $this->exec('git tag --list ' . escapeshellarg('v12.4.45.*'))));
        $tags = array_values(array_filter($tags, static fn(string $tag): bool => preg_match('/^v12\.4\.45\.[1-9]\d*$/', $tag) === 1));
        usort($tags, 'version_compare');
        return $tags;
    }

    /**
     * @return list<string>
     */
    private function getSystemExtensions(string $tag): array
    {
        $tree = $this->exec(sprintf('git ls-tree -d --name-only %s', escapeshellarg($tag . ':typo3/sysext')));
        return array_values(array_filter(explode("\n", $tree)));
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->rootPath . '/' . $path;
    }

    private function exec(string $command, ?int &$exitCode = null): string
    {
        $output = [];
        exec('cd ' . escapeshellarg($this->rootPath) . ' && ' . $command, $output, $exitCode);
        return implode("\n", $output);
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

exit((new ComposerRepositoryBuilder($argv))->run());
