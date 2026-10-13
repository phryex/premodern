#!/usr/bin/php
<?php
require_once __DIR__ . '/deckDownloadHelpers.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(challengeMain());
    } catch (Throwable $error) {
        fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

function challengeMain(): int
{
    $options = getopt('y:m:d:l:c:sh', ['date:', 'help']);
    if (isset($options['h']) || isset($options['help'])) {
        echo "Download published Premodern Challenges and print decks with classification guesses.\n"
            . "Usage: php downloadChallenges.php [--date YYYY-MM-DD] [-l directory] [-c dataset] [-s]\n"
            . "  Default date: today in America/New_York; -y YEAR -m MONTH -d DAY also supported.\n"
            . "  Default output: challenges/ beside this script. One JSON file per event.\n"
            . "  Default dataset: leagues/training/classifications.json beside this script.\n"
            . "  -s hides fetch logging; all decks and guesses are printed on every run.\n";
        return 0;
    }
    $today = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    $date = $options['date'] ?? sprintf('%04d-%02d-%02d',
        $options['y'] ?? $today->format('Y'),
        $options['m'] ?? $today->format('m'),
        $options['d'] ?? $today->format('d'));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new RuntimeException('Invalid date; use YYYY-MM-DD.');
    }
    $directory = $options['l'] ?? __DIR__ . '/challenges';
    $silent = isset($options['s']);
    $events = findChallengeEvents(challengeDownload('https://www.mtgo.com/decklists?filter=Premodern', $silent), $date);
    if ($events === []) {
        echo "No published Premodern Challenge found for $date. Results may not be posted yet." . PHP_EOL;
        return 0;
    }
    $classifier = loadClassifierDataset(resolveClassifierFilename($options['c'] ?? null, __DIR__ . '/leagues'));
    $failed = false;
    $summaries = [];
    foreach ($events as $slug => $url) {
        try {
            $payload = extractChallengeData(challengeDownload($url, $silent));
            if (($payload['site_name'] ?? null) !== $slug || ($payload['format'] ?? null) !== 'CPREMODERN') {
                throw new RuntimeException('Downloaded event does not match the requested Premodern Challenge.');
            }
            $decks = normalizeChallengeDecks($payload);
            if ($decks === []) {
                echo "$slug: no decks published yet; no file written." . PHP_EOL;
                continue;
            }
            $filename = rtrim($directory, '/\\') . '/' . $slug . '.json';
            saveChallengeDecks($filename, $decks);
            echo PHP_EOL . ($payload['description'] ?? $slug) . " ($date)" . PHP_EOL . $url . PHP_EOL;
            $results = [];
            foreach (prettyArray($decks) as $key => $deck) {
                $results[$key] = printNewDeck($deck, $classifier);
            }
            echo 'Saved ' . count($decks) . ' Challenge decks to ' . $filename . PHP_EOL;
            $summaries[$slug] = challengeSummary($payload, $decks, $results);
        } catch (Throwable $error) {
            fwrite(STDERR, $slug . ': ' . $error->getMessage() . PHP_EOL);
            $failed = true;
        }
    }
    foreach ($summaries as $summary) {
        echo PHP_EOL . $summary;
    }
    return $failed ? 1 : 0;
}

function orderChallengeDecks(array $payload, array $decks): array
{
    $ranks = [];
    // Decklist order and Swiss standings do not represent final playoff places.
    foreach ($payload['final_rank'] ?? [] as $standing) {
        $rank = filter_var($standing['rank'] ?? null, FILTER_VALIDATE_INT);
        if (isset($standing['loginid']) && $rank !== false && $rank > 0) {
            $ranks[(string) $standing['loginid']] = $rank;
        }
    }
    uasort($decks, static function ($left, $right) use ($ranks) {
        return (($ranks[(string) $left['loginid']] ?? PHP_INT_MAX)
            <=> ($ranks[(string) $right['loginid']] ?? PHP_INT_MAX))
            ?: strcmp($left['player'], $right['player']);
    });
    return $decks;
}

function challengeSummary(array $payload, array $decks, array $results): string
{
    $output = '';
    foreach (orderChallengeDecks($payload, $decks) as $key => $deck) {
        $result = $results[$key] ?? null;
        $classification = 'Unknown, confirm manually';
        if ($result !== null && !($result['is_unknown'] ?? true)) {
            $classification = $result['rules']['classification'] ?? $result['classification'];
        }
        $output .= str_replace(["\t", "\r", "\n"], ' ', $classification) . PHP_EOL;
    }
    return $output;
}

function challengeDownload(string $url, bool $silent): string
{
    if (!$silent) {
        echo 'Fetching ' . $url . PHP_EOL;
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'PremodernChallengeDownloader/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false || $status !== 200) {
        if (strpos($error, 'certificate') !== false) {
            $error .= ' Configure curl.cainfo in php.ini with a trusted CA bundle, or run php -d curl.cainfo=/path/to/cacert.pem downloadChallenges.php.';
        }
        throw new RuntimeException("Unable to fetch $url (HTTP $status): $error");
    }
    return $body;
}

function findChallengeEvents(string $html, string $date): array
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        throw new RuntimeException('Unable to parse the decklist index.');
    }
    $events = [];
    $hasDeckLinks = false;
    foreach ($document->getElementsByTagName('a') as $anchor) {
        $href = $anchor->getAttribute('href');
        $host = parse_url($href, PHP_URL_HOST);
        if ($host !== null && $host !== false && strtolower($host) !== 'www.mtgo.com') {
            continue;
        }
        $path = parse_url($href, PHP_URL_PATH) ?? '';
        if (strpos($path, '/decklist/') === 0) {
            $hasDeckLinks = true;
        }
        if (preg_match('~^/decklist/(premodern-challenge-\d+-' . preg_quote($date, '~') . '\d+)/?$~', $path, $match)) {
            $events[$match[1]] = 'https://www.mtgo.com/decklist/' . $match[1];
        }
    }
    if (!$hasDeckLinks) {
        throw new RuntimeException('Index contains no decklist links; cannot determine whether a Challenge was published.');
    }
    return $events;
}

function extractChallengeData(string $html): array
{
    if (!preg_match('/window\.MTGO\.decklists\.data\s*=\s*(\{)/', $html, $match, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('Event page is missing embedded deck data.');
    }
    $start = $match[1][1];
    $depth = 0;
    $quoted = false;
    $escaped = false;
    // Scan the JSON object so punctuation in player/card names cannot truncate it.
    for ($i = $start, $length = strlen($html); $i < $length; $i++) {
        $char = $html[$i];
        if ($quoted) {
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $quoted = false;
            }
        } elseif ($char === '"') {
            $quoted = true;
        } elseif ($char === '{') {
            $depth++;
        } elseif ($char === '}' && --$depth === 0) {
            return json_decode(substr($html, $start, $i - $start + 1), true, 512, JSON_THROW_ON_ERROR);
        }
    }
    throw new RuntimeException('Event page has truncated deck data.');
}

function normalizeChallengeDecks(array $payload): array
{
    if (!isset($payload['decklists']) || !is_array($payload['decklists'])) {
        throw new RuntimeException('Event data is missing decklists.');
    }
    $decks = [];
    foreach ($payload['decklists'] as $source) {
        if (!isset($source['decktournamentid'], $source['player'], $source['tournamentid'], $source['loginid'])) {
            throw new RuntimeException('Challenge deck is missing player or tournament identifiers.');
        }
        $key = $source['decktournamentid'] . '-' . $source['player'];
        if (isset($decks[$key])) {
            throw new RuntimeException('Duplicate Challenge deck: ' . $key);
        }
        $deck = array_intersect_key($source, array_flip(['player', 'loginid', 'tournamentid', 'decktournamentid']));
        foreach (['main_deck' => 'MD', 'sideboard_deck' => 'SB'] as $field => $section) {
            if (!isset($source[$field]) || !is_array($source[$field])) {
                throw new RuntimeException("Missing $field for $key.");
            }
            $cards = [];
            foreach ($source[$field] as $card) {
                $name = $card['card_attributes']['card_name'] ?? null;
                $qty = filter_var($card['qty'] ?? null, FILTER_VALIDATE_INT);
                if (!is_string($name) || $name === '' || $qty === false || $qty < 1) {
                    throw new RuntimeException('Invalid card data for ' . $key);
                }
                $cards[$name] = ($cards[$name] ?? 0) + $qty;
            }
            if ($section === 'MD' && $cards === []) {
                throw new RuntimeException('Empty main deck for ' . $key);
            }
            ksort($cards);
            $deck[$section] = $cards;
        }
        $decks[$key] = $deck;
    }
    return orderChallengeDecks($payload, $decks);
}

function saveChallengeDecks(string $filename, array $decks): void
{
    if (is_file($filename)) {
        $existing = json_decode(file_get_contents($filename), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($existing) || array_diff_key($existing, $decks) !== []) {
            throw new RuntimeException('Downloaded data omits previously saved decks; preserving ' . $filename);
        }
    }
    $json = json_encode(cleanJSON(prettyArray($decks)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    $directory = dirname($filename);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create directory: ' . $directory);
    }
    $temporary = tempnam($directory, '.challenge-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create temporary output file.');
    }
    try {
        if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $filename)) {
            throw new RuntimeException('Cannot save ' . $filename);
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}
