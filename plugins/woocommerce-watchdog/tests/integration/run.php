<?php

$GLOBALS['bwTests'] = [];
$GLOBALS['bwObservations'] = [];
$bwFailures = [];

function bw_test(string $name, callable $callback): void
{
    $GLOBALS['bwTests'][$name] = $callback;
}

function bw_assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$filter = getenv('BW_TEST_FILTER') ?: '';
$results = [];

foreach ($GLOBALS['bwTests'] as $name => $callback) {
    if ($filter !== '' && strpos($name, $filter) === false) {
        continue;
    }

    try {
        $callback();
        $results[] = ['test' => $name, 'ok' => true];
    } catch (Throwable $exception) {
        $results[] = ['test' => $name, 'ok' => false, 'error' => $exception->getMessage()];
        $bwFailures[] = $name;
    }
}

echo wp_json_encode([
    'environment' => \BusinessWatchdog\WooCommerce\Compat\Environment::describe(),
    'observations' => $GLOBALS['bwObservations'],
    'passed' => count($results) - count($bwFailures),
    'failed' => count($bwFailures),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($bwFailures !== []) {
    WP_CLI::halt(1);
}
