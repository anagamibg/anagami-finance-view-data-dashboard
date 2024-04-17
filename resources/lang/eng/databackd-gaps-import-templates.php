<?php

// AFV (S8, B1b): column-mapping templates for exports of accounting programs.
return [
    'platforms' => [
        'microinvest' => [
            'name' => 'Microinvest',
            'desc' => 'Microinvest Delta Pro: trial balance and chronological register, exported to Excel.',
        ],
        'ajur' => [
            'name' => 'Ajur',
            'desc' => 'Ajur L by Bonev Soft: trial balance and chronological register, exported to Excel.',
        ],
        'plus_minus' => [
            'name' => 'Plus Minus',
            'desc' => 'Plus Minus 7 (ERP): synthetic trial balance and journal of operations report, exported to Excel.',
        ],
        'business_navigator' => [
            'name' => 'Business Navigator',
            'desc' => 'Business Navigator (Komers Finans): trial balance of general ledger accounts and chronological journal, exported to Excel or CSV.',
        ],
    ],

    'layouts' => [
        'trial_balance' => [
            'name' => 'Trial balance',
            'how' => 'One row is imported per account: for revenue and expense accounts the turnover of the period, for balance sheet accounts the closing balance (on their natural side). Total rows and rows with a zero value are skipped. Analytic accounts are imported on their synthetic account, and the row of an analytic account whose synthetic account is in the file too is skipped. If the trial balance was taken after revenue and expense accounts were closed, tick the option below the date.',
        ],
        'journal' => [
            'name' => 'Journal of entries',
            'how' => 'Every entry with a debit and a credit account is imported on its revenue or expense account (debit of an expense account or credit of a revenue account). Entries between balance sheet accounts only are skipped. Closing entries (between revenue and expense accounts or to an account 12x), rows with a zero amount and mirror rows (the amount is in the other column) are skipped. A reversal (debit of revenue or credit of expense) is rejected as an error with a negative amount.',
        ],
    ],

    'templates' => [
        'microinvest_trial_balance' => [
            'name' => 'Microinvest · trial balance',
            'desc' => 'The trial balance report of Delta Pro exported to Excel (Ctrl+F11): account, name, opening balance, turnover and closing balance with debit and credit.',
        ],
        'microinvest_journal' => [
            'name' => 'Microinvest · chronological register',
            'desc' => 'The chronological register report of Delta Pro exported to Excel: date, document, debit and credit account, amount, partner and reason.',
        ],
        'ajur_trial_balance' => [
            'name' => 'Ajur · trial balance',
            'desc' => 'Synthetic trial balance from Ajur L (Reports › Trial balances), exported to Excel: account, name, opening balance, turnover and closing balance in debit (Dt) and credit (Kt). Delete the title rows above the column names.',
        ],
        'ajur_journal' => [
            'name' => 'Ajur · chronological register',
            'desc' => 'Accounting entries from the Ajur L chronological register, exported to Excel: reg. no., date, document, Dt account, Kt account, amount, counterparty and reason. Delete the title rows above the column names.',
        ],
        'plus_minus_trial_balance' => [
            'name' => 'Plus Minus · trial balance',
            'desc' => 'Reports › Trial balances › Synthetic, exported to Excel: code, name, opening balance, turnover and closing balance with debit and credit. The column headers must be on a single row.',
        ],
        'plus_minus_journal' => [
            'name' => 'Plus Minus · journal of operations',
            'desc' => 'Journal of operations report exported to Excel: date, document, number, debit, credit, analytics, amount and comment. The amount is in the base currency.',
        ],
        'business_navigator_trial_balance' => [
            'name' => 'Business Navigator · trial balance',
            'desc' => 'Reports – Trial balances – For general ledger accounts, with "Without sub-accounts" ticked and exported to Excel: account, opening balance, turnover and closing balance (debit and credit) and name.',
        ],
        'business_navigator_journal' => [
            'name' => 'Business Navigator · chronology',
            'desc' => 'Reports – Synthetic journals – Chronological, exported to Excel: period, entry, account, corresponding account, debit and credit. Every posting comes on two rows and is taken once – the row with a debit amount.',
        ],
    ],

    // Cards in "Integrations"
    'sections' => [
        'platforms' => 'Accounting programs and banks',
        'platforms_hint' => 'Ready-made templates for exports of the most common accounting programs and import of bank statements. The columns in the templates are typical ones and can be corrected in the mapping step.',
    ],
    'cards' => [
        'microinvest' => [
            'title' => 'Microinvest',
            'desc' => 'Trial balance and chronological register from Delta Pro, exported to Excel, with a ready-made column mapping template.',
            'action' => 'Import with a template',
        ],
        'ajur' => [
            'title' => 'Ajur',
            'desc' => 'Trial balance and chronological register from Ajur L, exported to Excel, with a ready-made column mapping template.',
            'action' => 'Import with a template',
        ],
        'plus_minus' => [
            'title' => 'Plus Minus',
            'desc' => 'Trial balance and journal of operations from Plus Minus, with a ready-made column mapping template.',
            'action' => 'Import with a template',
        ],
        'business_navigator' => [
            'title' => 'Business Navigator',
            'desc' => 'Trial balance of general ledger accounts and chronological journal from Business Navigator, with a ready-made column mapping template.',
            'action' => 'Import with a template',
        ],
        'bank_statements' => [
            'title' => 'Bank Statements',
            'desc' => 'Import bank statements in MT940 and CAMT.053 format.',
            'action' => 'Import a statement',
        ],
    ],

    // Wizard step 1
    'select' => [
        'label' => 'Platform template',
        'auto' => 'Automatic / manual mapping',
        'help' => 'Choose a template if the file is an export of an accounting program: the columns are matched by their typical names. Without a template the columns are recognised generically and you can change them.',
        'format' => 'File: CSV or Excel (delimiter and encoding are detected automatically). With a template, title rows above the table (company, period) and a heading split over two rows are recognized automatically.',
        'typical' => 'Typical for the program: delimiter ":delimiter", decimal separator ":decimal", encoding :encoding.',
        'warning' => 'The columns in the templates are typical for these exports but have not been checked against real exports of the programs. If a column is not found, choose it by hand in the next step.',
        'download_sample' => 'Download an example',
        'date_label' => 'Trial balance date',
        'date_help' => 'The last day of the period of the trial balance. It is applied to all rows when the file has no date column.',
        'after_closing' => 'Trial balance is after year-end closing',
        'after_closing_help' => 'Revenue and expense accounts are already closed to account 123, so their turnover on the natural side is taken (debit for expenses, credit for revenues). Credit notes and reversals within the period are then not netted. Without it the net turnover is taken.',
        'date_placeholder' => 'Select a date',
    ],

    // Computed columns (added at the end of the table)
    'virtual' => [
        'amount' => 'Amount for import (computed)',
        'account' => 'Account for import (computed)',
        'date' => 'Trial balance date (from the form)',
    ],

    'roles' => [
        'account' => 'Account',
        'date' => 'Date',
        'open_debit' => 'Opening balance debit',
        'open_credit' => 'Opening balance credit',
        'turnover_debit' => 'Turnover debit',
        'turnover_credit' => 'Turnover credit',
        'close_debit' => 'Closing balance debit',
        'close_credit' => 'Closing balance credit',
        'entity' => 'Legal entity',
        'currency' => 'Currency',
        'document' => 'Document',
        'debit_account' => 'Debit account',
        'credit_account' => 'Credit account',
        'amount' => 'Amount',
        'counterparty' => 'Counterparty',
        'description' => 'Description',
        'mirror_amount' => 'Credit amount (mirror row)',
    ],

    'skipped' => [
        'header_rows' => 'Title rows above the table',
        'totals' => 'Total rows (no account code)',
        'zero' => 'Rows with a zero value',
        'mirror' => 'Mirror rows (the amount is in the other column)',
        'analytic' => 'Analytic accounts included in their synthetic account',
        'balance_only' => 'Entries between balance sheet accounts only',
        'closing' => 'Closing entries',
    ],

    // Wizard step 2
    'mapping' => [
        'title' => 'Template: :name',
        'badge' => 'template',
        'computed_badge' => 'computed',
        'computed' => 'The template added computed columns at the end of the table and matched the columns automatically. Check the mapping and change it if needed.',
        'direct' => 'The template matched the columns it found. Columns needed to compute the amount are missing, so choose the remaining fields by hand.',
        'found' => 'Columns found',
        'missing' => 'Not found (choose them by hand): :columns.',
        'rows' => 'Rows to import: :out of :in in the file.',
        'skipped' => 'Skipped rows',
    ],

    'messages' => [
        'date_required' => 'The file has no date column — enter the "Trial balance date".',
        'invalid_date' => 'The trial balance date is not valid.',
        'no_rows' => 'No rows are left to import after applying the template (zero values, total rows or entries between balance sheet accounts only).',
        'unknown_template' => 'Unknown template.',
    ],
];
