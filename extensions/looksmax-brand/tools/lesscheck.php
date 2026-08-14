<?php

/**
 * Compile this extension's LESS in isolation.
 *
 * When the merged stylesheet fails to build, the forum returns a 500 and the
 * exception names a byte offset into the concatenation of a dozen extensions'
 * LESS — which identifies nothing. This compiles only our files with the same
 * compiler Flarum uses (wikimedia/less.php), so "is it ours" is answered in a
 * second instead of by bisecting other people's stylesheets.
 *
 *   docker exec flarum-app php /flarum/extensions/looksmax-brand/tools/lesscheck.php
 */
require '/flarum/app/vendor/autoload.php';

$dir = __DIR__.'/../less';
$files = $argc > 1 ? array_slice($argv, 1) : glob($dir.'/*.less');
$bad = 0;

foreach ($files as $file) {
    $parser = new Less_Parser(['compress' => false]);
    try {
        $parser->parseFile($file, '/');
        printf("OK    %7dB  %s\n", strlen($parser->getCss()), $file);
    } catch (Throwable $e) {
        $bad++;
        printf("FAIL           %s\n      %s\n", $file, $e->getMessage());
    }
}

exit($bad ? 1 : 0);
