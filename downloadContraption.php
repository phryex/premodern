#!/usr/bin/php
<?php
require_once __DIR__ . '/naiveBayesClassifier.php';
require_once __DIR__ . '/deckClassificationRules.php';

$description = "Script for downloading contraption leagues";
$shortOptions = array(
    'y:' => 'year',
    'm:' => 'month',
    'd:' => 'day',
    's'  => 'silence logging',
    't:' => 'tournament code',
    'l:' => 'directory to store JSON files',
    'c:' => 'classifier dataset (defaults to <league directory>/training/classifications.json)',
    'h'  => 'Display this.',
);
$options = getOpts($shortOptions, [], $description);
// Use UTC for date if not provided
date_default_timezone_set('UTC');
$year    = $options['y'] ?? date('Y');
$month   = $options['m'] ?? date('m');
$day     = $options['d'] ?? date('d');
$hour    = intval(date('H'));
$minute  = intval(date('i'));
$end     = $options['t'] ?? "10076";
$dir     = $options['l'] ?? ".";
$silence = isset($options['s']);
$classifierFilename = resolveClassifierFilename($options['c'] ?? null, $dir);
$classifier = loadClassifierDataset($classifierFilename);

// For first 15 minutes of the day check the previous day instead
if ($year == date('Y') && $month == date('m') && $day == date('d') && $hour == 0 && $minute <= 15) {
    $year  = date("Y", strtotime("-1 days"));
    $month = date("m", strtotime("-1 days"));
    $day   = date("d", strtotime("-1 days"));
}
date_default_timezone_set("America/New_York");

if (!$silence) {
    echo date('Y-m-d H:i:s') . " Starting downloadContraption.php" . PHP_EOL;
}
$file = "League-$end-$year-$month-$day.json";
$base = "https://www.mtgo.com/decklist/premodern-league-";
$link = $base . $year . "-" . $month . "-" . $day . $end;
if (!$silence) {
    echo date('Y-m-d H:i:s') . " Fetching " . $link . PHP_EOL;
}

$return = download($link, $silence);
if (empty($return)) {
    echo date("Y-m-d H:i:s") . " Failed to Scrape " . PHP_EOL;
    exit(1);
}
$stringBegin = "window.MTGO.decklists.data = ";
$stringEnd   = ";";

$newReturn    = substr($return, strpos($return, $stringBegin) + 29);
$newestReturn = substr($newReturn, 0, strpos($newReturn, $stringEnd));

$json = json_decode($newestReturn, true);
if (!isset($json["decklists"])) {
    echo date("Y-m-d H:i:s") . " No Decklists Found " . PHP_EOL;
    exit(0);
}

$decklists = [];
// Build initial decklists array
foreach ($json["decklists"] as $key => $value) {
    $uniqueKey = $value["loginplayeventcourseid"] . '-' . $value["player"];
        $decklists[$uniqueKey] ??= [
            'player'                 => $value['player'],
            'loginplayeventcourseid' => $value['loginplayeventcourseid'],
            'loginid'                => $value['loginid'],
            'instance_id'            => $value['instance_id'],
            'MD' => [],
            'SB' => [],
        ];
    // Hash to avoid issues of different sets per card
    foreach ($value["main_deck"] as $key2 => $value2) {
        // Check if card exists in deck already else start at 0
        $decklists[$uniqueKey]["MD"][$value2["card_attributes"]["card_name"]] ??= 0;
        $decklists[$uniqueKey]["MD"][$value2["card_attributes"]["card_name"]] += $value2["qty"];
    }
    ksort($decklists[$uniqueKey]["MD"]);
    foreach ($value["sideboard_deck"] as $key2 => $value2) {
        // Check if card exists in sb already else start at 0
        $decklists[$uniqueKey]["SB"][$value2["card_attributes"]["card_name"]] ??= 0;
        $decklists[$uniqueKey]["SB"][$value2["card_attributes"]["card_name"]] += $value2["qty"];
    }
    ksort($decklists[$uniqueKey]["SB"]);
}
// Prettify array for Terminal output
$decklists = prettyArray($decklists);
if (!file_exists($dir . "/" . $file)) {
    if (count($decklists) > 0) {
        foreach ($decklists as $key => $value) {
            printNewDeck($decklists[$key], $classifier);
        }
    }
} else {
    // Pull and compare
    $existingJson = file_get_contents($dir . "/" . $file);
    if ($existingJson === false) {
        echo date("Y-m-d H:i:s") . " Existing decklist file could not be read; preserving it" . PHP_EOL;
        exit(1);
    }

    $data = json_decode($existingJson, true);
    if (!is_array($data)) {
        echo date("Y-m-d H:i:s") . " Existing decklist file contains invalid JSON; preserving it: "
            . json_last_error_msg() . PHP_EOL;
        exit(1);
    }
    foreach ($decklists as $key => $value) {
        $uniqueKey = $value["loginplayeventcourseid"] . '-' . $value["player"];
        if (!isset($data[$uniqueKey])) {
            printNewDeck($decklists[$key], $classifier);
        }
    }
}

$decklistJson = json_encode(cleanJSON($decklists), JSON_PRETTY_PRINT);
// Check explicitly for previously stored decks via keys as well as counts
$missingDecks = array_diff_key($data ?? [], $decklists);
// We should never get less than previous, if so it's a website issue - don't store
if (count($decklists) >= count($data ?? []) && $missingDecks === []) {
    file_put_contents($dir . "/" . $file, $decklistJson);
}

echo date('Y-m-d H:i:s') . " There are " . count($decklists) . " 5-0 League Decks today" . PHP_EOL;
if (!$silence) {
    echo date('Y-m-d H:i:s') . " Finished downloadContraption.php" . PHP_EOL;
}

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
        return;
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
        return;
    }

    echo sprintf(
        'Naive Bayes: %s (%d%% confidence)%s',
        $result['classification'],
        $confidencePercent,
        $outputEnding
    );
    printRulesEngineAdjustment($result['rules'] ?? null);
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

function download($url, $silent)
{

    $ch = curl_init();
    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/107.0.0.0 Safari/537.36',
        CURLOPT_POST           => 0,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_CONNECTTIMEOUT => 100,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    curl_setopt_array($ch, $options);
    if (!$silent) {
        echo date('Y-m-d H:i:s') . " Curling $url" . PHP_EOL;
    }

    // Running curl to download file
    $returnBody = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        echo date('Y-m-d H:i:s') . " cURL error : " . curl_error($ch) . PHP_EOL;
        return false;
    } else {
        if ($statusCode !== 200) {
            echo date('Y-m-d H:i:s') . " File could not be downloaded, http error code: {$statusCode}" . PHP_EOL;
            return false;
        }
    }

    if (!$silent) {
        echo date('Y-m-d H:i:s') . " Successful Curl" . PHP_EOL;
    }
    return $returnBody;
}

// Basic framework stuff for getting options - jammed it here to be more portable
function getOpts($shortOptions, $longOptions = array(), $description = '')
{
    $shortOptsKeys = array_keys($shortOptions);
    $longOptsKeys  = array_keys($longOptions);

    # Native PHP's getopt() expects the first argument to be a string
    $options = getopt(implode('', $shortOptsKeys), $longOptsKeys);

    // check all required options are filled.
    $allOptions = array_merge($shortOptions, $longOptions);

    if (isset($options['h'])) {
        printOptionsHelp($shortOptions, $longOptions, $description);
        exit(1);
    }

    if ($options === false) {
        throw new \Exception("Unable to process options.");
    }
    return $options;
}
function printOptionsHelp($shortOptions, $longOptions, $description)
{
    if (strlen($description) > 0) {
        echo PHP_EOL, "$description";
    }
    echo PHP_EOL, "Options:", PHP_EOL, PHP_EOL;

    foreach ($shortOptions as $flag => $description) {
        if (strpos($flag, '::') !== false) {
            $values = '<OPTIONAL> ';
        } elseif (strpos($flag, ':') !== false) {
            $values = '<REQUIRED> ';
        } else {
            $values = '';
        }
        $flag = str_pad(str_replace(':', '', '-' . $flag), 25);
        echo "  {$flag}:   {$values}{$description}", PHP_EOL;
    }
    foreach ($longOptions as $flag => $description) {
        if (strpos($flag, '::') !== false) {
            $values = '<OPTIONAL> ';
        } elseif (strpos($flag, ':') !== false) {
            $values = '<REQUIRED> ';
        } else {
            $values = '';
        }
        $flag = str_pad(str_replace(':', '', '--' . $flag), 25);
        echo "  {$flag}:   {$values}{$description}", PHP_EOL;
    }
}
