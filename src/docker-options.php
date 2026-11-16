<?php

// Executado antes da comparação para descobrir a rede e a pasta do modelo.
require_once __DIR__ . '/config.php';

try {
    if ($argc !== 3) {
        throw new RuntimeException('Uso: docker-options.php nome config.php');
    }
    $entry = readConfigEntry($argv[2], $argv[1]);
    $model = configuredModelPath($entry, dirname(realpath($argv[2])));
    echo configuredDockerNetwork($entry) . PHP_EOL . dirname($model) . PHP_EOL;
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'ERRO: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
