<?php

declare(strict_types=1);

// The patch script has to be LF, otherwise every multi-line literal inside it
// carries a \r\n that the normalised stylesheet does not contain.
$path = __DIR__ . '/patch_v7.php';
$content = file_get_contents($path);
$fixed = str_replace("\r\n", "\n", $content);

file_put_contents($path, $fixed);

echo 'crlf before: ' . substr_count($content, "\r\n") . ', after: ' . substr_count($fixed, "\r\n") . PHP_EOL;
