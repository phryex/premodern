#!/usr/bin/php
<?php

declare(strict_types=1);

require_once __DIR__ . '/trainingConversionHelpers.php';

try {
    exit(convertDecksToTrainingMain(
        $argv,
        __DIR__ . DIRECTORY_SEPARATOR . 'challenges',
        'premodern-challenge-*.json',
        'Challenge'
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
