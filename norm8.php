<?php

declare(strict_types=1);

// Make the patch script LF so its multi-line literals match the stylesheet.
$target = __DIR__ . '/patch_v8.php';
$content = (string) file_get_contents($target);

file_put_contents($target, str_replace("\r\n", "\n", $content));

echo "normalised: " . substr_count($content, "\r\n") . " CRLF removed\n";
