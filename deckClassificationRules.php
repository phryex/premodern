<?php

declare(strict_types=1);

function applyDeckClassificationRules(array $deck, string $classification): array
{
    $originalClassification = trim($classification);
    $adjustedClassification = $originalClassification;
    $reasons = [];

    if (strcasecmp($adjustedClassification, 'UW Standstill') === 0
        && !deckContainsCard($deck, 'Standstill')) {
        $adjustedClassification = 'UW Control';
        $reasons[] = 'Standstill is absent';
    }

    $hasMoxDiamond = deckContainsCard($deck, 'Mox Diamond');
    if ($hasMoxDiamond && classificationContainsWord($adjustedClassification, 'Moxless')) {
        $adjustedClassification = removeClassificationWord($adjustedClassification, 'Moxless');
        $reasons[] = 'Mox Diamond is present';
    }

    if (!$hasMoxDiamond) {
        if (strcasecmp($adjustedClassification, 'Gro') === 0) {
            $adjustedClassification = 'Moxless Gro';
            $reasons[] = 'Mox Diamond is absent';
        } elseif (stripos($adjustedClassification, 'Ponza Oath') !== false
            && !classificationContainsWord($adjustedClassification, 'Moxless')) {
            $adjustedClassification = 'Moxless ' . $adjustedClassification;
            $reasons[] = 'Mox Diamond is absent';
        }
    }

    if (stripos($adjustedClassification, 'Terrageddon') !== false) {
        $hasMeddlingMage = deckContainsCard($deck, 'Meddling Mage');
        $hasVindicate = deckContainsCard($deck, 'Vindicate');
        $hasEngineeredPlague = deckContainsCard($deck, 'Engineered Plague');
        $terrageddonClassification = null;
        $terrageddonReason = null;

        if ($hasMeddlingMage && $hasVindicate && $hasEngineeredPlague) {
            $terrageddonClassification = '5C Terrageddon';
            $terrageddonReason =
                'Meddling Mage, Vindicate, and Engineered Plague are present';
        } elseif ($hasMeddlingMage && !$hasVindicate && !$hasEngineeredPlague) {
            $terrageddonClassification = 'GWRU Terrageddon';
            $terrageddonReason =
                'Meddling Mage is present without Vindicate or Engineered Plague';
        } elseif ($hasVindicate && !$hasMeddlingMage) {
            $terrageddonClassification = 'GWBR Terrageddon';
            $terrageddonReason = 'Vindicate is present without Meddling Mage';
        } elseif (!$hasMeddlingMage && !$hasVindicate && !$hasEngineeredPlague) {
            $terrageddonClassification = 'GWR Terrageddon';
            $terrageddonReason =
                'Meddling Mage, Vindicate, and Engineered Plague are absent';
        }

        if ($terrageddonClassification !== null
            && strcasecmp($adjustedClassification, $terrageddonClassification) !== 0) {
            $adjustedClassification = $terrageddonClassification;
            $reasons[] = $terrageddonReason;
        }
    }

    if (classificationContainsWord($adjustedClassification, 'HFEB')
        && !deckContainsCard($deck, 'Hermit Druid')) {
        $adjustedClassification = replaceClassificationWord(
            $adjustedClassification,
            'HFEB',
            'CFEB'
        );
        $reasons[] = 'Hermit Druid is absent';
    }

    return [
        'classification' => $adjustedClassification,
        'original_classification' => $originalClassification,
        'changed' => strcasecmp($originalClassification, $adjustedClassification) !== 0,
        'reasons' => $reasons,
    ];
}

function deckContainsCard(array $deck, string $wantedCardName): bool
{
    foreach (['MD', 'SB'] as $section) {
        foreach ($deck[$section] ?? [] as $line) {
            if (!is_string($line)) {
                continue;
            }

            $cardName = preg_replace('/^\s*\d+\s+/', '', trim($line));
            if (strcasecmp($cardName, $wantedCardName) === 0) {
                return true;
            }
        }
    }

    return false;
}

function classificationContainsWord(string $classification, string $word): bool
{
    return preg_match('/\b' . preg_quote($word, '/') . '\b/i', $classification) === 1;
}

function removeClassificationWord(string $classification, string $word): string
{
    $classification = preg_replace(
        '/\b' . preg_quote($word, '/') . '\b[\s:-]*/i',
        '',
        $classification
    );

    return normalizeClassification($classification);
}

function replaceClassificationWord(
    string $classification,
    string $word,
    string $replacement
): string {
    $classification = preg_replace(
        '/\b' . preg_quote($word, '/') . '\b/i',
        $replacement,
        $classification
    );

    return normalizeClassification($classification);
}

function normalizeClassification(string $classification): string
{
    $classification = preg_replace('/\s+/', ' ', $classification);
    return trim($classification, " \t\n\r\0\x0B-:");
}
