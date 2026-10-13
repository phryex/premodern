#!/usr/bin/php
<?php

declare(strict_types=1);

try {
    exit(main($argv));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function main(array $argv): int
{
    $options = parseArguments($argv);
    if ($options['help']) {
        printHelp($argv[0]);
        return 0;
    }

    $leagueDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'leagues';
    $sourceFiles = $options['filename'] === null
        ? findRawLeagueFiles($leagueDirectory)
        : [resolveInputFilename($options['filename'], $leagueDirectory)];
    $outputDirectory = resolveOutputDirectory($options['output_directory']);

    if (!is_dir($outputDirectory)
        && !mkdir($outputDirectory, 0777, true)
        && !is_dir($outputDirectory)) {
        throw new RuntimeException('Unable to create output directory: ' . $outputDirectory);
    }

    $created = 0;
    $updated = 0;
    $unchanged = 0;
    $deckCount = 0;

    foreach ($sourceFiles as $sourceFilename) {
        $trainingDecks = convertLeagueFile($sourceFilename);
        $deckCount += count($trainingDecks);

        $json = json_encode(
            $trainingDecks,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new RuntimeException(
                'Unable to encode ' . basename($sourceFilename) . ': ' . json_last_error_msg()
            );
        }
        $json .= PHP_EOL;

        $outputFilename = $outputDirectory . DIRECTORY_SEPARATOR
            . 'TRAINING_' . basename($sourceFilename);
        $existing = is_file($outputFilename) ? file_get_contents($outputFilename) : false;

        if ($existing === $json) {
            $unchanged++;
            continue;
        }

        $wasExisting = is_file($outputFilename);
        $bytes = file_put_contents($outputFilename, $json, LOCK_EX);
        if ($bytes === false) {
            throw new RuntimeException('Unable to write ' . $outputFilename);
        }

        if ($wasExisting) {
            $updated++;
        } else {
            $created++;
        }
    }

    echo 'Raw league files processed: ' . count($sourceFiles) . PHP_EOL;
    echo 'Deck records converted:    ' . $deckCount . PHP_EOL;
    echo 'Training files created:    ' . $created . PHP_EOL;
    echo 'Training files updated:    ' . $updated . PHP_EOL;
    echo 'Training files unchanged:  ' . $unchanged . PHP_EOL;
    echo 'Output directory:          ' . $outputDirectory . PHP_EOL;

    return 0;
}

function parseArguments(array $argv): array
{
    $filename = null;
    $outputDirectory = null;
    $help = false;

    for ($index = 1, $count = count($argv); $index < $count; $index++) {
        $argument = $argv[$index];

        if ($argument === '-h' || $argument === '--help') {
            $help = true;
            continue;
        }

        if ($argument === '-o' || $argument === '--output-dir') {
            if (!isset($argv[$index + 1])) {
                throw new InvalidArgumentException($argument . ' requires a directory.');
            }
            $outputDirectory = $argv[++$index];
            continue;
        }

        if (strpos($argument, '--output-dir=') === 0) {
            $outputDirectory = substr($argument, strlen('--output-dir='));
            if ($outputDirectory === '') {
                throw new InvalidArgumentException('--output-dir requires a directory.');
            }
            continue;
        }

        if (strpos($argument, '-') === 0) {
            throw new InvalidArgumentException('Unknown option: ' . $argument);
        }

        if ($filename !== null) {
            throw new InvalidArgumentException('Only one input filename may be supplied.');
        }
        $filename = $argument;
    }

    return [
        'filename' => $filename,
        'output_directory' => $outputDirectory,
        'help' => $help,
    ];
}

function printHelp(string $script): void
{
    $script = basename($script);
    echo <<<HELP
Convert raw league JSON into classifier training JSON

Usage:
  php {$script} [raw-league-file] [--output-dir <directory>]

With no input filename, every League-*.json file directly inside leagues is
converted. Existing output files are updated only when their generated content
has changed.

Defaults:
  Input directory:  leagues
  Output directory: leagues/training
  Output filename:  TRAINING_<original-filename>

Options:
  -o, --output-dir <directory>  Use a different output directory
  -h, --help                    Display this help

HELP;
}

function findRawLeagueFiles(string $leagueDirectory): array
{
    $files = glob($leagueDirectory . DIRECTORY_SEPARATOR . 'League-*.json');
    if ($files === false) {
        throw new RuntimeException('Unable to scan raw league directory: ' . $leagueDirectory);
    }

    $files = array_values(array_filter($files, static function (string $filename): bool {
        return is_file($filename) && is_readable($filename);
    }));
    natcasesort($files);
    $files = array_values($files);

    if ($files === []) {
        throw new RuntimeException('No League-*.json files found in ' . $leagueDirectory);
    }

    return $files;
}

function resolveInputFilename(string $filename, string $leagueDirectory): string
{
    $candidates = [$filename];
    if (!isAbsolutePath($filename)) {
        $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . $filename;
        $candidates[] = $leagueDirectory . DIRECTORY_SEPARATOR . $filename;
    }

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $resolved = realpath($candidate);
            return $resolved === false ? $candidate : $resolved;
        }
    }

    throw new InvalidArgumentException('Could not find readable raw league file: ' . $filename);
}

function resolveOutputDirectory(?string $directory): string
{
    if ($directory === null) {
        return __DIR__ . DIRECTORY_SEPARATOR . 'leagues' . DIRECTORY_SEPARATOR . 'training';
    }

    $resolvedDirectory = $directory;
    if (!isAbsolutePath($resolvedDirectory)) {
        $resolvedDirectory = getcwd() . DIRECTORY_SEPARATOR . $resolvedDirectory;
    }

    $existingDirectory = realpath($resolvedDirectory);
    return $existingDirectory === false ? $resolvedDirectory : $existingDirectory;
}

function isAbsolutePath(string $filename): bool
{
    return preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/]{2}|/)~', $filename) === 1;
}

function convertLeagueFile(string $filename): array
{
    $contents = file_get_contents($filename);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $filename);
    }

    $decks = json_decode($contents, true);
    if (!is_array($decks)) {
        throw new RuntimeException(
            'Invalid JSON in ' . basename($filename) . ': ' . json_last_error_msg()
        );
    }

    $trainingDecks = [];
    foreach ($decks as $deckId => $deck) {
        if (!is_array($deck)
            || !isset($deck['MD'], $deck['SB'])
            || !is_array($deck['MD'])
            || !is_array($deck['SB'])) {
            throw new RuntimeException(
                'Deck ' . $deckId . ' in ' . basename($filename) . ' must contain MD and SB arrays.'
            );
        }

        $trainingDecks[$deckId] = [
            'MD' => alphabetizeDeckSection($deck['MD'], $deckId, 'MD', $filename),
            'SB' => alphabetizeDeckSection($deck['SB'], $deckId, 'SB', $filename),
        ];
    }

    return $trainingDecks;
}

function alphabetizeDeckSection(
    array $section,
    $deckId,
    string $sectionName,
    string $filename
): array {
    foreach ($section as $line) {
        if (!is_string($line)) {
            throw new RuntimeException(
                'Deck ' . $deckId . ' in ' . basename($filename)
                . ' contains a non-string ' . $sectionName . ' entry.'
            );
        }
    }

    $section = array_values($section);
    usort($section, static function (string $left, string $right): int {
        $leftName = preg_replace('/^\s*\d+\s+/', '', $left);
        $rightName = preg_replace('/^\s*\d+\s+/', '', $right);
        $comparison = strcasecmp($leftName, $rightName);

        return $comparison !== 0 ? $comparison : strcmp($left, $right);
    });

    return $section;
}
