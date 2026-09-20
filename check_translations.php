<?php

declare(strict_types=1);

require_once __DIR__ . '/src/helpers/translations.php';

$t = learning_app_translations();
$en = array_keys($t['en']);
$de = array_keys($t['de']);

echo 'en keys: ' . count($en) . ' | de keys: ' . count($de) . PHP_EOL;
echo 'only in en: ' . implode(', ', array_diff($en, $de)) . PHP_EOL;
echo 'only in de: ' . implode(', ', array_diff($de, $en)) . PHP_EOL;
echo 'app.brand en: ' . $t['en']['app.brand'] . ' | de: ' . $t['de']['app.brand'] . PHP_EOL;
