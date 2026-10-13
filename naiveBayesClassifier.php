<?php

declare(strict_types=1);

const NAIVE_BAYES_MIN_CONFIDENCE = 0.55;

function classifyDeckWithNaiveBayes(array $dataset, array $deck): ?array
{
    $fingerprint = deckFingerprint($deck);
    if (isset($dataset['examples'][$fingerprint])) {
        $classification = trim($dataset['examples'][$fingerprint]['classification']);
        if ($classification !== '') {
            return [
                'classification' => $classification,
                'confidence' => 1.0,
                'is_unknown' => false,
                'match_type' => 'exact',
            ];
        }
    }

    $suggestion = naiveBayesSuggestion($dataset, $deck);
    if ($suggestion !== null) {
        $suggestion['match_type'] = 'naive_bayes';
    }

    return $suggestion;
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
