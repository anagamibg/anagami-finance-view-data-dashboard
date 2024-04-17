<?php

return [
    'menu' => 'Import History',
    'title' => 'Import History',
    'subtitle' => 'All financial data imports — files, Google Sheets, CSV and API — with status, errors and the option to undo.',
    'show_title' => 'Import #:id',

    'cards' => [
        'total' => 'Total imports',
        'imported' => 'Imported rows',
        'failed' => 'Rows with errors',
        'undone' => 'Undone imports',
    ],

    'filters' => [
        'source' => 'Source',
        'status' => 'Status',
        'all' => 'All',
        'search' => 'Search by file or URL…',
    ],

    'columns' => [
        'number' => '#',
        'date' => 'Date and Time',
        'source' => 'Source',
        'file' => 'File / URL',
        'rows_total' => 'Total',
        'rows_imported' => 'Successful',
        'rows_failed' => 'Failed',
        'status' => 'Status',
        'user' => 'User',
        'actions' => 'Actions',
    ],

    'api_token' => 'API token',
    'system' => 'System',
    'no_file' => '—',

    'actions' => [
        'view' => 'Details',
        'undo' => 'Undo import',
        'back' => 'Back to history',
        'view_entries' => 'View entries',
        'export_errors' => 'Download the rows with errors (CSV)',
    ],

    'meta' => [
        'title' => 'Import Details',
        'source' => 'Source',
        'file' => 'File',
        'url' => 'URL',
        'user' => 'Imported by',
        'created_at' => 'Started',
        'finished_at' => 'Last update',
        'status' => 'Status',
        'mapping' => 'Column mapping',
        'mapping_empty' => 'No mapping saved (data was sent with ready field names).',
        'mapping_bank' => 'A bank statement is read automatically according to its format standard - there are no columns to map.',
        'column' => 'Column :letter',
    ],

    'counters' => [
        'total' => 'Total rows',
        'imported' => 'Successful',
        'failed' => 'Failed',
        'entries_now' => 'Entries now',
    ],

    'errors' => [
        'title' => 'Import Errors',
        'row' => 'Row #',
        'reasons' => 'Reasons',
        'empty_title' => 'No errors',
        'empty_text' => 'All rows of this import were processed successfully.',
        'truncated' => 'Showing the first :shown of :total failed rows.',
        'stored_note' => 'The row number is the row in the file (the header row is #1).',
        'export_name' => 'import-errors-:id.csv',
    ],

    'sample' => [
        'title' => 'Sample Imported Entries',
        'empty_title' => 'No entries',
        'empty_text' => 'There are currently no entries linked to this import.',
        'date' => 'Date',
        'account' => 'Account',
        'entity' => 'Legal entity',
        'amount' => 'Amount',
        'showing' => 'Showing the first :shown of :total entries.',
    ],

    'undo' => [
        'confirm_title' => 'Undo Import',
        'confirm_text' => 'All entries created by this import will be deleted. This cannot be reversed.',
        'confirm_text_count' => 'All entries of this import (:count) will be deleted. Reports, analyses and the dashboard will be recalculated without them. This cannot be reversed, but you can import the file again.',
        'confirm_button' => 'Yes, undo the import',
        'cancel' => 'Cancel',
        'success' => 'The import was undone. Deleted entries: :count.',
        'not_allowed' => 'This import cannot be undone.',
        'already_undone' => 'The import has already been undone.',
        'processing' => 'The import is still processing and cannot be undone.',
        'no_entries' => 'There are no entries to undo.',
        'undone_note' => 'This import was undone — its entries have been deleted.',
        'processing_note' => 'The import is being processed right now. Refresh the page in a moment.',
    ],

    'empty' => [
        'title' => 'No imports yet',
        'text' => 'When you import data from a file, Google Sheets or the API, every import will appear here.',
    ],

    'csv' => [
        'file_row' => 'Row in the file',
        'row' => 'Row',
        'reasons' => 'Reasons',
    ],
];
