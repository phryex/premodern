#!/usr/bin/php
<?php

declare(strict_types=1);

const NAIVE_BAYES_MIN_CONFIDENCE = 0.55;

require_once __DIR__ . '/deckClassificationRules.php';

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

    $trainingDirectory = resolveTrainingDirectory($options['directory']);
    $inputFiles = findTrainingFiles($trainingDirectory);
    $outputFilename = resolveOutputFilename($options['output']);
    if (file_exists($outputFilename)) {
        $resolvedOutput = realpath($outputFilename);
        foreach ($inputFiles as $inputFile) {
            if ($resolvedOutput !== false && $resolvedOutput === realpath($inputFile)) {
                throw new InvalidArgumentException(
                    'The output dataset must not overwrite a training input file.'
                );
            }
        }
    }
    $dataset = loadDataset($outputFilename);

    echo 'Training directory: ' . $trainingDirectory . PHP_EOL;
    echo 'Training files:     ' . count($inputFiles) . PHP_EOL;
    echo 'Output:             ' . $outputFilename . PHP_EOL;
    echo 'Saved examples:     ' . count($dataset['examples']) . PHP_EOL;
    echo PHP_EOL;

    $newLabels = 0;
    $alreadyLabeled = 0;
    $duplicateOccurrences = 0;
    $skipped = 0;
    $quit = false;
    $filesProcessed = 0;
    $decksScanned = 0;
    $seenFingerprints = [];
    $datasetDirty = false;

    foreach ($inputFiles as $fileIndex => $inputFilename) {
        $decks = loadDeckFile($inputFilename);
        $filesProcessed++;
        echo sprintf(
            'Scanning file %d/%d: %s (%d decks)%s',
            $fileIndex + 1,
            count($inputFiles),
            basename($inputFilename),
            count($decks),
            PHP_EOL
        );

        $position = 0;
        foreach ($decks as $deckId => $deck) {
            $position++;
            $decksScanned++;
            $fingerprint = deckFingerprint($deck);
            $source = [
                'file' => basename($inputFilename),
                'deck_id' => (string) $deckId,
            ];

            if (isset($seenFingerprints[$fingerprint])) {
                if (isset($dataset['examples'][$fingerprint])
                    && addSource($dataset['examples'][$fingerprint], $source)) {
                    $datasetDirty = true;
                }
                $duplicateOccurrences++;
                continue;
            }
            $seenFingerprints[$fingerprint] = true;

            $existing = $dataset['examples'][$fingerprint] ?? null;
            if ($existing !== null && !$options['relabel']) {
                if (addSource($dataset['examples'][$fingerprint], $source)) {
                    $datasetDirty = true;
                }
                $alreadyLabeled++;
                continue;
            }

            echo 'New deck found in ' . basename($inputFilename) . PHP_EOL;
            printDeck((string) $deckId, $deck, $position, count($decks));
            $knownClassifications = knownClassifications($dataset);
            $currentClassification = $existing['classification'] ?? null;
            $suggestion = naiveBayesSuggestion($dataset, $deck, $fingerprint);
            if ($suggestion !== null) {
                $suggestion['rules'] = applyDeckClassificationRules(
                    $deck,
                    $suggestion['classification']
                );
            }
            $answer = promptForClassification(
                $knownClassifications,
                $currentClassification,
                $suggestion
            );

            if ($answer['action'] === 'quit') {
                $quit = true;
                break 2;
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
            $datasetDirty = false;
            $newLabels++;

            echo 'Saved as ' . $answer['classification'] . '.' . PHP_EOL . PHP_EOL;
        }

        if ($datasetDirty) {
            saveDataset($outputFilename, $dataset);
            $datasetDirty = false;
        }
    }

    if ($datasetDirty) {
        saveDataset($outputFilename, $dataset);
    }

    echo PHP_EOL;
    echo $quit ? 'Stopped early. All completed labels were saved.' . PHP_EOL : 'Finished training directory.' . PHP_EOL;
    echo 'Training files processed: ' . $filesProcessed . PHP_EOL;
    echo 'Deck occurrences scanned: ' . $decksScanned . PHP_EOL;
    echo 'New or updated labels: ' . $newLabels . PHP_EOL;
    echo 'Previously labeled unique decks: ' . $alreadyLabeled . PHP_EOL;
    echo 'Repeated deck occurrences ignored: ' . $duplicateOccurrences . PHP_EOL;
    echo 'Skipped decks: ' . $skipped . PHP_EOL;
    echo 'Total saved examples: ' . count($dataset['examples']) . PHP_EOL;

    return 0;
}

function parseArguments(array $argv): array
{
    $directory = null;
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

        if ($directory !== null) {
            throw new InvalidArgumentException('Only one training directory may be supplied.');
        }

        $directory = $argument;
    }

    return [
        'directory' => $directory,
        'output' => $output,
        'relabel' => $relabel,
        'help' => $help,
    ];
}

function printHelp(string $script): void
{
    $script = basename($script);
    echo <<<HELP
Interactive Magic deck training-directory labeler

Usage:
  php {$script} [directory] [--output <filename>] [--relabel]

By default, every TRAINING_*.json file in leagues/training is scanned in
filename order. Previously classified and repeated decklists are skipped.
Labels are saved after every answer to the same default dataset used by the
single-file labeler:
  leagues/training/classifications.json

Prompt commands:
  y / yes Accept a Naive Bayes hint above the confidence threshold
  :skip   Leave this deck unlabeled and continue
  :quit   Stop; labels already entered remain saved

Options:
  directory                  Scan a directory other than leagues/training
  -o, --output <filename>  Use a different classifications dataset
      --relabel            Prompt once for every unique saved deck as well
  -h, --help               Display this help

HELP;
}

function resolveTrainingDirectory(?string $directory): string
{
    if ($directory === null) {
        $directory = __DIR__ . DIRECTORY_SEPARATOR . 'leagues' . DIRECTORY_SEPARATOR . 'training';
    }

    $candidates = [$directory];

    if (!isAbsolutePath($directory)) {
        $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . $directory;
    }

    foreach (array_unique($candidates) as $candidate) {
        if (is_dir($candidate) && is_readable($candidate)) {
            $resolved = realpath($candidate);
            return $resolved === false ? $candidate : $resolved;
        }
    }

    throw new InvalidArgumentException('Could not find readable training directory: ' . $directory);
}

function findTrainingFiles(string $directory): array
{
    $files = glob($directory . DIRECTORY_SEPARATOR . 'TRAINING_*.json');
    if ($files === false) {
        throw new RuntimeException('Unable to scan training directory: ' . $directory);
    }

    $files = array_values(array_filter($files, static function (string $filename): bool {
        return is_file($filename) && is_readable($filename);
    }));
    natcasesort($files);
    $files = array_values($files);

    if ($files === []) {
        throw new RuntimeException('No TRAINING_*.json files found in ' . $directory);
    }

    return $files;
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
    $sourceDeckId = $source['deck_id'] ?? null;

    foreach ($record['sources'] as $existingSource) {
        $existingDeckId = $existingSource['deck_id'] ?? null;
        if ($sourceDeckId !== null && $existingDeckId === $sourceDeckId) {
            return false;
        }
        if ($sourceDeckId === null
            && $existingDeckId === null
            && ($existingSource['file'] ?? null) === ($source['file'] ?? null)) {
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

function naiveBayesSuggestion(array $dataset, array $deck, ?string $excludedFingerprint = null): ?array
{
    $classNames = [];
    $classDocumentCounts = [];
    $classTokenCounts = [];
    $classTotalTokens = [];
    $vocabulary = [];
    $totalDocuments = 0;

    foreach ($dataset['examples'] as $fingerprint => $example) {
        if ($fingerprint === $excludedFingerprint) {
            continue;
        }

        $classification = trim($example['classification']);
        if ($classification === '') {
            continue;
        }

        $classKey = strtolower($classification);
        $exampleWeight = trainingExampleWeight($example);
        $classNames[$classKey] = $classNames[$classKey] ?? $classification;
        $classDocumentCounts[$classKey] =
            ($classDocumentCounts[$classKey] ?? 0) + $exampleWeight;
        $classTokenCounts[$classKey] = $classTokenCounts[$classKey] ?? [];
        $classTotalTokens[$classKey] = $classTotalTokens[$classKey] ?? 0;
        $totalDocuments += $exampleWeight;

        foreach (deckFeatures($example) as $token => $count) {
            $classTokenCounts[$classKey][$token] =
                ($classTokenCounts[$classKey][$token] ?? 0) + ($count * $exampleWeight);
            $classTotalTokens[$classKey] += $count * $exampleWeight;
            $vocabulary[$token] = true;
        }
    }

    if ($totalDocuments === 0 || $vocabulary === []) {
        return null;
    }

    $features = deckFeatures($deck);

    $classCount = count($classDocumentCounts);
    $vocabularySize = count($vocabulary);
    $scores = [];
    ksort($classDocumentCounts, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($classDocumentCounts as $classKey => $documentCount) {
        $score = log(($documentCount + 1) / ($totalDocuments + $classCount));
        $denominator = $classTotalTokens[$classKey] + $vocabularySize;

        foreach ($features as $token => $count) {
            if (!isset($vocabulary[$token])) {
                continue;
            }

            $tokenCount = $classTokenCounts[$classKey][$token] ?? 0;
            $score += $count * log(($tokenCount + 1) / $denominator);
        }

        $scores[$classKey] = $score;
    }

    arsort($scores, SORT_NUMERIC);
    $bestClassKey = array_key_first($scores);
    if ($bestClassKey === null) {
        return null;
    }

    $bestScore = $scores[$bestClassKey];
    $probabilityDenominator = 0.0;
    foreach ($scores as $score) {
        $probabilityDenominator += exp($score - $bestScore);
    }
    $topProbability = $probabilityDenominator > 0.0
        ? 1.0 / $probabilityDenominator
        : 0.0;
    $similarity = closestClassSimilarity(
        $dataset,
        $deck,
        $classNames[$bestClassKey],
        $excludedFingerprint
    );
    $confidence = $topProbability * $similarity['similarity'];

    return [
        'classification' => $classNames[$bestClassKey],
        'training_occurrences' => $totalDocuments,
        'class_count' => $classCount,
        'confidence' => $confidence,
        'closest_similarity' => $similarity['similarity'],
        'matching_features' => $similarity['matching_features'],
        'is_unknown' => $classCount < 2 || $confidence < NAIVE_BAYES_MIN_CONFIDENCE,
    ];
}

function closestClassSimilarity(
    array $dataset,
    array $deck,
    string $classification,
    ?string $excludedFingerprint
): array {
    $targetFeatures = array_fill_keys(array_keys(deckFeatures($deck)), true);
    $bestSimilarity = 0.0;
    $bestMatchingFeatures = 0;

    foreach ($dataset['examples'] as $fingerprint => $example) {
        if ($fingerprint === $excludedFingerprint
            || strcasecmp($example['classification'], $classification) !== 0) {
            continue;
        }

        $candidateFeatures = array_fill_keys(array_keys(deckFeatures($example)), true);
        $matchingFeatures = 0;
        foreach ($targetFeatures as $token => $_present) {
            if (isset($candidateFeatures[$token])) {
                $matchingFeatures++;
            }
        }

        $unionSize = count($targetFeatures) + count($candidateFeatures) - $matchingFeatures;
        $candidateSimilarity = $unionSize > 0 ? $matchingFeatures / $unionSize : 0.0;
        if ($candidateSimilarity > $bestSimilarity) {
            $bestSimilarity = $candidateSimilarity;
            $bestMatchingFeatures = $matchingFeatures;
        }
    }

    return [
        'similarity' => $bestSimilarity,
        'matching_features' => $bestMatchingFeatures,
    ];
}

function trainingExampleWeight(array $example): int
{
    $occurrences = [];

    foreach ($example['sources'] ?? [] as $source) {
        $deckId = trim((string) ($source['deck_id'] ?? ''));
        if ($deckId !== '') {
            $occurrences['deck:' . $deckId] = true;
            continue;
        }

        $sourceFile = trim((string) ($source['file'] ?? ''));
        if ($sourceFile !== '') {
            $occurrences['file:' . $sourceFile] = true;
        }
    }

    return max(1, count($occurrences));
}

function deckFeatures(array $deck): array
{
    $features = [];

    foreach (['MD', 'SB'] as $section) {
        foreach ($deck[$section] as $line) {
            $quantity = 1;
            $cardName = trim($line);

            if (preg_match('/^\s*(\d+)\s+(.+?)\s*$/u', $line, $matches) === 1) {
                $quantity = max(1, (int) $matches[1]);
                $cardName = $matches[2];
            }

            if ($cardName === '') {
                continue;
            }

            $token = $section . ':' . strtolower($cardName);
            $features[$token] = ($features[$token] ?? 0) + $quantity;
        }
    }

    return $features;
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

function promptForClassification(
    array $knownClassifications,
    ?string $currentClassification,
    ?array $suggestion
): array
{
    if ($suggestion !== null) {
        $confidencePercent = (int) round($suggestion['confidence'] * 100);
        $rules = $suggestion['rules'] ?? [
            'classification' => $suggestion['classification'],
            'changed' => false,
            'reasons' => [],
        ];

        if ($suggestion['is_unknown']) {
            if ($suggestion['matching_features'] === 0) {
                echo 'Naive Bayes hint: Unknown (no familiar card profile)' . PHP_EOL;
            } else {
                echo sprintf(
                    'Naive Bayes hint: Unknown (closest: %s; %d%% confidence)%s',
                    $suggestion['classification'],
                    $confidencePercent,
                    PHP_EOL
                );
            }
        } else {
            echo sprintf(
                'Naive Bayes hint: %s (%d%% confidence; %d appearances)%s',
                $suggestion['classification'],
                $confidencePercent,
                $suggestion['training_occurrences'],
                PHP_EOL
            );
        }

        if ($rules['changed']) {
            echo sprintf(
                'Rules engine: %s (%s)%s',
                $rules['classification'],
                implode('; ', $rules['reasons']),
                PHP_EOL
            );
        }

        if (!$suggestion['is_unknown']) {
            echo 'Accept recommendation? [y/N]: ';
            $line = fgets(STDIN);
            if ($line === false) {
                echo PHP_EOL;
                return ['action' => 'quit', 'classification' => null];
            }

            $answer = trim($line);
            if (strcasecmp($answer, 'y') === 0 || strcasecmp($answer, 'yes') === 0) {
                return [
                    'action' => 'label',
                    'classification' => $rules['classification'],
                ];
            }
            if ($answer === ':quit') {
                return ['action' => 'quit', 'classification' => null];
            }
            if ($answer === ':skip') {
                return ['action' => 'skip', 'classification' => null];
            }
        }
    }

    while (true) {
        if ($currentClassification !== null) {
            echo 'Current classification: ' . $currentClassification . PHP_EOL;
            echo 'Press Enter to keep it.' . PHP_EOL;
        }

        echo 'Classification (:skip; :quit): ';
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
