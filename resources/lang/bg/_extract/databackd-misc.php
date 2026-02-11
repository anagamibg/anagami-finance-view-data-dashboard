<?php

// FRAGMENT of resources/lang/bg/databackd-misc.php - merge into the root array (keys used by the data import and the Google Sheets reader).
// Not a standalone lang file: copy the keys into the existing file of the host project.
return [
    'close' => 'Затвори',
    'source_rate_limited' => 'Твърде много опити за изтегляне на данни. Опитайте отново след :seconds сек.',
    'import' => [
        'too_many_sheets' => 'Каченият файл съдържа твърде много листове.',
        'too_large_cells' => 'Каченият файл е твърде голям (твърде много редове или колони).',
        'too_large_rows' => 'Каченият файл е твърде голям (твърде много редове).',
    ],
    'sheets' => [
        'redirect_blocked' => 'Източникът пренасочва към непозволен адрес.',
        'url_not_allowed' => 'Адресът не е разрешен. Поддържат се само https адреси на Google Sheets (docs.google.com).',
        'too_large' => 'Файлът е твърде голям (максимум :max MB).',
        'too_many_redirects' => 'Адресът пренасочва твърде много пъти.',
        'too_many_rows' => 'Файлът има твърде много редове (максимум :max).',
    ],
];
