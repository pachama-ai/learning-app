<?php

declare(strict_types=1);

foreach (['patch_tiles3.php', 'new_grid.css'] as $name) {
    $target = __DIR__ . '/' . $name;

    if (!is_file($target)) {
        continue;
    }

    $content = (string) file_get_contents($target);
    file_put_contents($target, str_replace("\r\n", "\n", $content));
    echo $name . ' normalised' . PHP_EOL;
}
