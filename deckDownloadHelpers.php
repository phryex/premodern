<?php
require_once __DIR__ . '/naiveBayesClassifier.php';
require_once __DIR__ . '/deckClassificationRules.php';

function resolveClassifierFilename($configuredFilename, $leagueDirectory)
{
    if ($configuredFilename !== null) {
        $filename = $configuredFilename;
        if (!isAbsoluteClassifierPath($filename)) {
            $filename = getcwd() . DIRECTORY_SEPARATOR . $filename;
        }
        $resolved = realpath($filename);
        return $resolved === false ? $filename : $resolved;
    }

    $candidates = [
        rtrim($leagueDirectory, '/\\') . DIRECTORY_SEPARATOR
            . 'training' . DIRECTORY_SEPARATOR . 'classifications.json',
        __DIR__ . DIRECTORY_SEPARATOR . 'leagues' . DIRECTORY_SEPARATOR
            . 'training' . DIRECTORY_SEPARATOR . 'classifications.json',
    ];

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $resolved = realpath($candidate);
            return $resolved === false ? $candidate : $resolved;
        }
    }

    return $candidates[0];
}

function isAbsoluteClassifierPath($filename)
{
    return preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/]{2}|/)~', $filename) === 1;
}

function loadClassifierDataset($filename)
{
    if (!is_file($filename) || !is_readable($filename)) {
        return [
            'dataset' => null,
            'error' => 'classification data not found at ' . $filename,
        ];
    }

    $contents = file_get_contents($filename);
    if ($contents === false) {
        return [
            'dataset' => null,
            'error' => 'classification data could not be read',
        ];
    }

    $dataset = json_decode($contents, true);
    if (!is_array($dataset)) {
        return [
            'dataset' => null,
            'error' => 'classification data contains invalid JSON: ' . json_last_error_msg(),
        ];
    }

    if (($dataset['format'] ?? null) !== 'magic-deck-naive-bayes-training'
        || ($dataset['version'] ?? null) !== 1
        || !isset($dataset['examples'])
        || !is_array($dataset['examples'])) {
        return [
            'dataset' => null,
            'error' => 'classification data has an unsupported format',
        ];
    }

    foreach ($dataset['examples'] as $fingerprint => $example) {
        if (!is_array($example)
            || !isset($example['classification'], $example['MD'], $example['SB'])
            || !is_string($example['classification'])
            || !is_array($example['MD'])
            || !is_array($example['SB'])) {
            return [
                'dataset' => null,
                'error' => 'classification data contains an invalid example: ' . $fingerprint,
            ];
        }
    }

    return ['dataset' => $dataset, 'error' => null];
}

function printNewDeck($deck, $classifier)
{
    echo "New Deck found :" . print_r($deck, true) . PHP_EOL;

    if ($classifier['dataset'] === null) {
        echo 'Naive Bayes: unavailable (' . $classifier['error'] . ')' . PHP_EOL . PHP_EOL;
        return;
    }

    try {
        $result = classifyDeckWithNaiveBayes($classifier['dataset'], $deck);
        if ($result !== null) {
            $result['rules'] = applyDeckClassificationRules(
                $deck,
                $result['classification']
            );
        }
    } catch (Throwable $exception) {
        echo 'Naive Bayes: unavailable (' . $exception->getMessage() . ')' . PHP_EOL . PHP_EOL;
        return;
    }

    if ($result === null) {
        echo 'Naive Bayes: unavailable (no usable labeled examples)' . PHP_EOL . PHP_EOL;
        return;
    }

    $confidencePercent = (int) round($result['confidence'] * 100);
    $hasRulesAdjustment = is_array($result['rules'] ?? null)
        && ($result['rules']['changed'] ?? false);
    $outputEnding = $hasRulesAdjustment ? PHP_EOL : PHP_EOL . PHP_EOL;
    if (($result['match_type'] ?? null) === 'exact') {
        echo sprintf(
            'Naive Bayes: %s (%d%% confidence; exact labeled list)%s',
            $result['classification'],
            $confidencePercent,
            $outputEnding
        );
        printRulesEngineAdjustment($result['rules'] ?? null);
        return $result;
    }

    if ($result['is_unknown']) {
        if ($result['matching_features'] === 0) {
            echo 'Naive Bayes: Unknown (no familiar card profile)' . $outputEnding;
        } else {
            echo sprintf(
                'Naive Bayes: Unknown (closest: %s; %d%% confidence)%s',
                $result['classification'],
                $confidencePercent,
                $outputEnding
            );
        }
        printRulesEngineAdjustment($result['rules'] ?? null);
        return $result;
    }

    echo sprintf(
        'Naive Bayes: %s (%d%% confidence)%s',
        $result['classification'],
        $confidencePercent,
        $outputEnding
    );
    printRulesEngineAdjustment($result['rules'] ?? null);
    return $result;
}

function printRulesEngineAdjustment($rules)
{
    if (!is_array($rules) || !($rules['changed'] ?? false)) {
        return;
    }

    echo sprintf(
        'Rules engine: %s (%s)%s%s',
        $rules['classification'],
        implode('; ', $rules['reasons']),
        PHP_EOL,
        PHP_EOL
    );
}

// Format array for cleaner print_r output, e.g. all keys are 2 chars
function prettyArray($decklists)
{
    // Cleanup to preferred formatting of numeric array keys => quantity cardname
    foreach ($decklists as $key => $value) {
        $decklists[$key]['MD2'] = [];
        $decklists[$key]['SB2'] = [];
        $i = 0;
        foreach ($value['MD'] as $key2 => $value2) {
            // Prettify the print_r output - every key is 2 chars
            $index = ($i < 10) ? '0' . $i : $i;
            $decklists[$key]['MD2'][$index] = $value2 . ' ' . $key2;
            unset($decklists[$key]['MD'][$key2]);
            $i++;
        }
        $decklists[$key]['MD'] = $decklists[$key]['MD2'];
        unset($decklists[$key]['MD2']);
        $i = 0;
        foreach ($value['SB'] as $key2 => $value2) {
            // Prettify the print_r output - every key is 2 chars
            $index = ($i < 10) ? '0' . $i : $i;
            $decklists[$key]['SB2'][$index] = $value2 . ' ' . $key2;
            unset($decklists[$key]['SB'][$key2]);
            $i++;
        }
        $decklists[$key]['SB'] = $decklists[$key]['SB2'];
        unset($decklists[$key]['SB2']);
    }
    return $decklists;
}

// Get rid of string keys for cleaner json output - no array keys
function cleanJSON($decklists)
{
    foreach($decklists as $key => $value) {
        $decklists[$key]['MD2'] = [];
        $decklists[$key]['SB2'] = [];

        // MD
        foreach ($value['MD'] as $key2 => $value2) {
            $decklists[$key]['MD2'][] = $value2;
            unset($decklists[$key]['MD'][$key2]);
        }
        $decklists[$key]['MD'] = $decklists[$key]['MD2'];
        unset($decklists[$key]['MD2']);

        // SB
        foreach ($value['SB'] as $key2 => $value2) {
            $decklists[$key]['SB2'][] = $value2;
            unset($decklists[$key]['SB'][$key2]);
        }
        $decklists[$key]['SB'] = $decklists[$key]['SB2'];
        unset($decklists[$key]['SB2']);
    }
    return $decklists;
}

