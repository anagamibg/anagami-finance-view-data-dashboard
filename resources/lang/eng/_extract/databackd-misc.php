<?php

// FRAGMENT of resources/lang/eng/databackd-misc.php - merge into the root array (keys used by the data import and the Google Sheets reader).
// Not a standalone lang file: copy the keys into the existing file of the host project.
return [
    'close' => 'Close',
    'source_rate_limited' => 'Too many attempts to download data. Try again in :seconds sec.',
    'import' => [
        'too_many_sheets' => 'The uploaded file has too many sheets.',
        'too_large_cells' => 'The uploaded file is too large (too many rows or columns).',
        'too_large_rows' => 'The uploaded file is too large (too many rows).',
    ],
    'sheets' => [
        'redirect_blocked' => 'The source redirects to a disallowed address.',
        'url_not_allowed' => 'The address is not allowed. Only https addresses of Google Sheets (docs.google.com) are supported.',
        'too_large' => 'The file is too large (maximum :max MB).',
        'too_many_redirects' => 'The address redirects too many times.',
        'too_many_rows' => 'The file has too many rows (maximum :max).',
    ],
];
