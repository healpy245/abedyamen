<?php

/**
 * Fails when the original Kaman orange, warm palette, or logo file changes.
 */

$root = dirname(__DIR__);
$css = file_get_contents($root.'/public/css/kaman.css');
$snapshot = file_get_contents($root.'/resources/design/kaman-theme.snapshot.css');

preg_match_all('/--kaman-[a-z0-9-]+:\s*[^;]+;/', $snapshot, $matches);
$lines = $matches[0] ?? [];

if ($lines === []) {
    fwrite(STDERR, "Snapshot has no theme lines.\n");
    exit(1);
}

$missing = [];
foreach ($lines as $line) {
    if (! str_contains($css, $line)) {
        $missing[] = trim($line);
    }
}

$logoPath = $root.'/public/kaman.png';
$expected = trim(strtok((string) file_get_contents($root.'/resources/design/kaman-logo.sha256'), ' '));
$actual = is_file($logoPath) ? hash_file('sha256', $logoPath) : '';

if ($missing !== [] || $actual !== $expected) {
    fwrite(STDERR, "Kaman brand check failed.\n");
    foreach ($missing as $line) {
        fwrite(STDERR, "  missing from public/css/kaman.css: {$line}\n");
    }
    if ($actual !== $expected) {
        fwrite(STDERR, "  logo hash changed.\n  expected {$expected}\n  actual   {$actual}\n");
    }
    exit(1);
}

echo 'Kaman brand intact. '.count($lines)." theme lines and public/kaman.png match the snapshot.\n";
