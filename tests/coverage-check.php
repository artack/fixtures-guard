<?php

declare(strict_types=1);

/*
 * Fails unless the Clover report covers every statement and method: composer coverage runs it after PHPUnit.
 */

$file = $argv[1] ?? '';

$xml = '' !== $file ? @simplexml_load_file($file) : false;
if (false === $xml || !isset($xml->project->metrics)) {
    fwrite(\STDERR, sprintf("coverage-check: no Clover report at \"%s\".\n", $file));

    exit(2);
}

$metrics = $xml->project->metrics;
$failed = false;

foreach (['statements' => 'coveredstatements', 'methods' => 'coveredmethods'] as $total => $covered) {
    $all = (int) $metrics[$total];
    $hit = (int) $metrics[$covered];

    printf("%s: %d of %d covered\n", $total, $hit, $all);
    $failed = $failed || $hit < $all;
}

if ($failed) {
    fwrite(\STDERR, "coverage-check: the coverage is below 100%.\n");

    exit(1);
}

echo "coverage-check: 100%\n";
