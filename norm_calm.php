<?php

declare(strict_types=1);

// Patch scripts must be LF: their multi-line literals have to match the
// LF-normalised target files, otherwise nothing matches.
foreach (['patch_calm.php'] as $name) {
    $target = __DIR__ . '/' . $name;

    if (!is_file($target)) {
        continue;
    }

    $content = (string) file_get_contents($target);
    file_put_contents($target, str_replace("\r\n", "\n", $content));
    echo $name . ' normalised' . PHP_EOL;
}
