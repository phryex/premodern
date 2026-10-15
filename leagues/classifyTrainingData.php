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

    if ($options['filename'] === null) {
        printHelp($argv[0]);
        return 1;
    }

    $inputFilename = resolveInputFilename($options['filename']);
    $outputFilename = resolveOutputFilename($options['output']);
    if (file_exists($outputFilename) && realpath($inputFilename) === realpath($outputFilename)) {
        throw new InvalidArgumentException('The input file and output dataset must be different files.');
    }
    $decks = loadDeckFile($inputFilename);
    $dataset = loadDataset($outputFilename);

    echo 'Input:  ' . $inputFilename . PHP_EOL;
    echo 'Output: ' . $outputFilename . PHP_EOL;
    echo 'Decks in file: ' . count($decks) . PHP_EOL;
    echo 'Saved examples: ' . count($dataset['examples']) . PHP_EOL;
    echo PHP_EOL;

    $newLabels = 0;
    $alreadyLabeled = 0;
    $skipped = 0;
    $quit = false;
    $position = 0;

    foreach ($decks as $deckId => $deck) {
        $position++;
        $fingerprint = deckFingerprint($deck);
        $existing = $dataset['examples'][$fingerprint] ?? null;
        $source = [
            'file' => basename($inputFilename),
            'deck_id' => (string) $deckId,
        ];

        if ($existing !== null && !$options['relabel']) {
            $sourceAdded = addSource($dataset['examples'][$fingerprint], $source);
            if ($sourceAdded) {
                saveDataset($outputFilename, $dataset);
            }

            $alreadyLabeled++;
            echo sprintf(
                "[%d/%d] %s: already classified as %s%s",
                $position,
                count($decks),
                $deckId,
                $existing['classification'],
                PHP_EOL
            );
            continue;
        }

        printDeck((string) $deckId, $deck, $position, count($decks));
        $knownClassifications = knownClassifications($dataset);
        $currentClassification = $existing['classification'] ?? null;
        $answer = promptForClassification($knownClassifications, $currentClassification);

        if ($answer['action'] === 'quit') {
            $quit = true;
            break;
        }

        if ($answer['action'] === 'skip') {
            $skipped++;
            echo "Skipped." . PHP_EOL . PHP_EOL;
            continue;
        }

        $now = gmdate('c');
        $sources = $existing['sources'] ?? [];
        $record = [
            'classification' => $answer['classification'],
            'MD' => array_values($deck['MD']),
            'SB' => array_values($deck['SB']),
            'sources' => $sources,
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ];
        addSource($record, $source);

        $dataset['examples'][$fingerprint] = $record;
        saveDataset($outputFilename, $dataset);
        $newLabels++;

        echo 'Saved as ' . $answer['classification'] . '.' . PHP_EOL . PHP_EOL;
    }

    echo PHP_EOL;
    echo $quit ? 'Stopped early. All completed labels were saved.' . PHP_EOL : 'Finished file.' . PHP_EOL;
    echo 'New or updated labels: ' . $newLabels . PHP_EOL;
    echo 'Previously labeled decks: ' . $alreadyLabeled . PHP_EOL;
    echo 'Skipped decks: ' . $skipped . PHP_EOL;
    echo 'Total saved examples: ' . count($dataset['examples']) . PHP_EOL;

    return 0;
}

function parseArguments(array $argv): array
{
    $filename = null;
    $output = null;
    $relabel = false;
    $help = false;

    for ($index = 1, $count = count($argv); $index < $count; $index++) {
        $argument = $argv[$index];

        if ($argument === '-h' || $argument === '--help') {
            $help = true;
            continue;
        }

        if ($argument === '--relabel') {
            $relabel = true;
            continue;
        }

        if ($argument === '-o' || $argument === '--output') {
            if (!isset($argv[$index + 1])) {
                throw new InvalidArgumentException($argument . ' requires a filename.');
            }
            $output = $argv[++$index];
            continue;
        }

        if (strpos($argument, '--output=') === 0) {
            $output = substr($argument, strlen('--output='));
            if ($output === '') {
                throw new InvalidArgumentException('--output requires a filename.');
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
        'output' => $output,
        'relabel' => $relabel,
        'help' => $help,
    ];
}

function printHelp(string $script): void
{
    $script = basename($script);
    echo <<<HELP
Interactive Magic deck training-data labeler

Usage:
  php {$script} <filename> [--output <filename>] [--relabel]

The input may be a full path or a basename from leagues/training. Labels are
saved after every answer. The default dataset is:
  leagues/training/classifications.json

Prompt commands:
  :skip   Leave this deck unlabeled and continue
  :quit   Stop; labels already entered remain saved

Options:
  -o, --output <filename>  Use a different classifications dataset
      --relabel            Prompt again for decks already in the dataset
  -h, --help               Display this help

HELP;
}

function resolveInputFilename(string $filename): string
{
    $candidates = [$filename];

    if (!isAbsolutePath($filename)) {
        $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . $filename;
        $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . 'leagues' . DIRECTORY_SEPARATOR
            . 'training' . DIRECTORY_SEPARATOR . $filename;

        $basename = basename($filename);
        if (strpos($basename, 'TRAINING_') !== 0) {
            $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . 'leagues' . DIRECTORY_SEPARATOR
                . 'training' . DIRECTORY_SEPARATOR . 'TRAINING_' . $basename;
        }
    }

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $resolved = realpath($candidate);
            return $resolved === false ? $candidate : $resolved;
        }
    }

    throw new InvalidArgumentException('Could not find readable input file: ' . $filename);
}

function resolveOutputFilename(?string $filename): string
{
    if ($filename === null) {
        return __DIR__ . DIRECTORY_SEPARATOR . 'leagues' . DIRECTORY_SEPARATOR
            . 'training' . DIRECTORY_SEPARATOR . 'classifications.json';
    }

    $resolvedFilename = $filename;
    if (!isAbsolutePath($resolvedFilename)) {
        $resolvedFilename = getcwd() . DIRECTORY_SEPARATOR . $resolvedFilename;
    }

    $resolvedDirectory = realpath(dirname($resolvedFilename));
    if ($resolvedDirectory !== false) {
        return $resolvedDirectory . DIRECTORY_SEPARATOR . basename($resolvedFilename);
    }

    return $resolvedFilename;
}

function isAbsolutePath(string $filename): bool
{
    return preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/]{2}|/)~', $filename) === 1;
}

function loadDeckFile(string $filename): array
{
    $contents = file_get_contents($filename);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $filename);
    }

    $decks = json_decode($contents, true);
    if (!is_array($decks)) {
        throw new RuntimeException('Invalid deck JSON: ' . json_last_error_msg());
    }

    foreach ($decks as $deckId => $deck) {
        if (!is_array($deck) || !isset($deck['MD'], $deck['SB'])) {
            throw new RuntimeException('Deck ' . $deckId . ' must contain MD and SB arrays.');
        }

        if (!is_array($deck['MD']) || !is_array($deck['SB'])) {
            throw new RuntimeException('Deck ' . $deckId . ' has a non-array MD or SB value.');
        }

        foreach (['MD', 'SB'] as $section) {
            foreach ($deck[$section] as $line) {
                if (!is_string($line)) {
                    throw new RuntimeException(
                        'Deck ' . $deckId . ' contains a non-string ' . $section . ' entry.'
                    );
                }
            }
        }
    }

    return $decks;
}

function loadDataset(string $filename): array
{
    if (!file_exists($filename)) {
        return [
            'format' => 'magic-deck-naive-bayes-training',
            'version' => 1,
            'examples' => [],
        ];
    }

    $contents = file_get_contents($filename);
    if ($contents === false) {
        throw new RuntimeException('Unable to read existing dataset: ' . $filename);
    }

    $dataset = json_decode($contents, true);
    if (!is_array($dataset)) {
        throw new RuntimeException('Invalid classifications JSON: ' . json_last_error_msg());
    }

    if (($dataset['format'] ?? null) !== 'magic-deck-naive-bayes-training'
        || ($dataset['version'] ?? null) !== 1
        || !isset($dataset['examples'])
        || !is_array($dataset['examples'])) {
        throw new RuntimeException('Unsupported classifications dataset format.');
    }

    foreach ($dataset['examples'] as $fingerprint => $example) {
        if (!is_array($example)
            || !isset($example['classification'], $example['MD'], $example['SB'])
            || !is_string($example['classification'])
            || !is_array($example['MD'])
            || !is_array($example['SB'])
            || (isset($example['sources']) && !is_array($example['sources']))) {
            throw new RuntimeException('Invalid saved example: ' . $fingerprint);
        }
        $dataset['examples'][$fingerprint]['sources'] = $example['sources'] ?? [];
    }

    return $dataset;
}

function saveDataset(string $filename, array $dataset): void
{
    $directory = dirname($filename);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create output directory: ' . $directory);
    }

    ksort($dataset['examples'], SORT_STRING);
    $json = json_encode(
        $dataset,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($json === false) {
        throw new RuntimeException('Unable to encode dataset: ' . json_last_error_msg());
    }

    $bytes = file_put_contents($filename, $json . PHP_EOL, LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('Unable to save dataset: ' . $filename);
    }
}

function deckFingerprint(array $deck): string
{
    $normalized = [
        'MD' => normalizedDeckSection($deck['MD']),
        'SB' => normalizedDeckSection($deck['SB']),
    ];
    $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to fingerprint deck: ' . json_last_error_msg());
    }

    return hash('sha256', $json);
}

function normalizedDeckSection(array $section): array
{
    $section = array_values($section);
    usort($section, static function (string $left, string $right): int {
        $leftName = preg_replace('/^\s*\d+\s+/', '', $left);
        $rightName = preg_replace('/^\s*\d+\s+/', '', $right);
        $comparison = strcasecmp($leftName, $rightName);

        return $comparison !== 0 ? $comparison : strcmp($left, $right);
    });

    return $section;
}

function addSource(array &$record, array $source): bool
{
    $record['sources'] = $record['sources'] ?? [];

    foreach ($record['sources'] as $existingSource) {
        if (($existingSource['file'] ?? null) === $source['file']
            && ($existingSource['deck_id'] ?? null) === $source['deck_id']) {
            return false;
        }
    }

    $record['sources'][] = $source;
    return true;
}

function knownClassifications(array $dataset): array
{
    $classifications = [];

    foreach ($dataset['examples'] as $example) {
        $classification = $example['classification'];
        $classifications[strtolower($classification)] = $classification;
    }

    natcasesort($classifications);
    return array_values($classifications);
}

function printDeck(string $deckId, array $deck, int $position, int $total): void
{
    echo str_repeat('=', 72) . PHP_EOL;
    echo sprintf('Deck %d/%d: %s', $position, $total, $deckId) . PHP_EOL;
    echo str_repeat('=', 72) . PHP_EOL;
    printDeckSection('Main deck', $deck['MD']);
    echo PHP_EOL;
    printDeckSection('Sideboard', $deck['SB']);
    echo PHP_EOL;
}

function printDeckSection(string $heading, array $section): void
{
    echo sprintf('%s (%d cards)', $heading, cardCount($section)) . PHP_EOL;
    foreach ($section as $line) {
        echo '  ' . $line . PHP_EOL;
    }
}

function cardCount(array $section): int
{
    $total = 0;
    foreach ($section as $line) {
        if (preg_match('/^\s*(\d+)\s+/', $line, $matches) === 1) {
            $total += (int) $matches[1];
        }
    }

    return $total;
}

function promptForClassification(array $knownClassifications, ?string $currentClassification): array
{
    while (true) {
        if ($knownClassifications !== []) {
            echo 'Known classifications:' . PHP_EOL;
            foreach ($knownClassifications as $index => $classification) {
                echo sprintf('  %2d) %s', $index + 1, $classification) . PHP_EOL;
            }
        }

        if ($currentClassification !== null) {
            echo 'Current classification: ' . $currentClassification . PHP_EOL;
            echo 'Press Enter to keep it.' . PHP_EOL;
        }

        echo 'Classification (number or new name; :skip; :quit): ';
        $line = fgets(STDIN);
        if ($line === false) {
            echo PHP_EOL;
            return ['action' => 'quit', 'classification' => null];
        }

        $answer = trim($line);
        if ($answer === ':quit') {
            return ['action' => 'quit', 'classification' => null];
        }
        if ($answer === ':skip') {
            return ['action' => 'skip', 'classification' => null];
        }
        if ($answer === '' && $currentClassification !== null) {
            return ['action' => 'label', 'classification' => $currentClassification];
        }
        if ($answer === '') {
            echo 'Enter a classification, :skip, or :quit.' . PHP_EOL . PHP_EOL;
            continue;
        }

        if (ctype_digit($answer)) {
            $selection = (int) $answer;
            if ($selection < 1 || $selection > count($knownClassifications)) {
                echo 'That classification number is not in the list.' . PHP_EOL . PHP_EOL;
                continue;
            }

            return [
                'action' => 'label',
                'classification' => $knownClassifications[$selection - 1],
            ];
        }

        $answer = preg_replace('/\s+/', ' ', $answer);
        foreach ($knownClassifications as $classification) {
            if (strcasecmp($answer, $classification) === 0) {
                $answer = $classification;
                break;
            }
        }

        return ['action' => 'label', 'classification' => $answer];
    }
}
