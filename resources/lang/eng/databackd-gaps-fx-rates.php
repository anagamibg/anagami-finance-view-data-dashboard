<?php

return [
    'menu' => 'Exchange Rates',
    'title' => 'Exchange Rates',
    'help' => 'Exchange rates by date for legal entities and entries in a currency other than the euro. One euro is worth the stated number of units of the currency (the European Central Bank convention). The rates are used when importing data (converting the amount to the company currency) and in the consolidation of legal entities that report in another currency.',
    'usage_note' => 'For a date without a rate the nearest earlier one is used, but not older than :days days. The Bulgarian lev is converted at the fixed rate of 1.95583 per euro and needs no entry.',
    'company_currency' => 'The company currency is :currency. Amounts in another currency are converted into it at the rate of the entry date.',

    'widgets' => [
        'total' => 'Rates',
        'currencies' => 'Currencies',
        'latest' => 'Latest rate date',
        'last_ecb' => 'Latest ECB rates',
    ],

    'ecb' => [
        'title' => 'European Central Bank',
        'description' => 'Fetches the current daily reference rates of the ECB for about 30 currencies against the euro and saves them under the date of the publication.',
        'note' => 'Rates are fetched only when you press the button; nothing is downloaded automatically. Your own (manual) rates for the same date and currency stay unchanged.',
        'button' => 'Fetch from ECB',
        'overwrite_manual' => 'Overwrite manual rates for the same date',
        'result' => 'Rates for :date were fetched. Currencies saved: :count.',
        'result_skipped' => 'Manual rates left unchanged: :count.',
        'result_time' => 'Fetched on :time.',
    ],

    'list' => [
        'title' => 'Rates by Date',
        'add' => 'Add rate',
        'currency' => 'Currency',
        'all_currencies' => 'All currencies',
        'from' => 'From date',
        'to' => 'To date',
        'source' => 'Source',
        'all_sources' => 'All sources',
        'apply' => 'Apply',
        'reset' => 'Clear',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'showing' => 'Rates :from – :to of :total',
        'rate_hint' => '1 EUR = :rate :currency',
        'bgn_unused' => 'not used',
        'bgn_unused_help' => 'The lev always uses the fixed rate of 1.95583.',
    ],

    'columns' => [
        'date' => 'Date',
        'currency' => 'Currency',
        'rate' => 'Rate (per 1 EUR)',
        'source' => 'Source',
        'actions' => 'Actions',
    ],

    'sources' => [
        'manual' => 'Manual',
        'ecb' => 'ECB',
    ],

    'empty' => [
        'title' => 'There are no exchange rates yet.',
        'text' => 'Fetch the current rates from the ECB or add a rate by hand with the "Add rate" button.',
        'filtered' => 'No rates match the selected filters.',
    ],

    'chart' => [
        'title' => 'Rate Over Time',
        'currency' => 'Currency',
        'series' => '1 EUR in :currency',
        'points' => ':count rates in the chart',
        'no_data' => 'There are no rates to show.',
    ],

    'form' => [
        'add_title' => 'New Exchange Rate',
        'edit_title' => 'Edit Exchange Rate',
        'date' => 'Date',
        'currency' => 'Currency',
        'currency_placeholder' => 'e.g. USD',
        'rate' => 'Rate',
        'rate_placeholder' => 'e.g. 1.0850',
        'help' => 'The rate is the number of units of the currency for 1 euro: 1 EUR = 1.0850 USD is entered as 1.0850. One rate per date and currency; the date cannot be in the future.',
        'bgn_note' => 'No rate is entered for the Bulgarian lev (BGN): the fixed rate of 1.95583 per euro always applies.',
        'preview' => '1 EUR = :rate :currency · 1 :currency = :inverse EUR',
        'ecb_edit_note' => 'This rate was fetched from the ECB. After you save it, it becomes a manual rate and is no longer replaced by the next ECB fetch (unless you choose to overwrite manual rates).',
        'save' => 'Save',
        'close' => 'Close',
    ],

    'delete' => [
        'title' => 'Are you sure?',
        'text' => 'The rate :label will be deleted.',
        'confirm' => 'Delete',
        'cancel' => 'Cancel',
    ],

    'messages' => [
        'saved' => 'The exchange rate has been saved.',
        'deleted' => 'The exchange rate has been deleted.',
        'ecb_done' => 'ECB rates for :date. Currencies saved: :count.',
    ],

    'errors' => [
        'network' => 'The ECB could not be reached (no connection or the request timed out). Check the internet connection and try again later; nothing was changed.',
        'http_status' => 'The ECB server answered with an error (HTTP :status). Try again later; nothing was changed.',
        'invalid_feed' => 'The response of the ECB could not be read as an exchange rate file. Try again later; nothing was changed.',
        'rate_limited' => 'Too many attempts to fetch rates. Try again later (up to 10 fetches per hour).',
        'unexpected' => 'The rates could not be fetched because of an unexpected error. Nothing was changed.',
    ],

    'validation' => [
        'date_future' => 'The date cannot be in the future.',
        'date_too_old' => 'The date cannot be before 1 January 1999.',
        'currency_format' => 'The currency must be a 3-letter code, e.g. USD.',
        'currency_not_allowed' => 'The euro is the base currency of the rates and is not entered.',
        'currency_bgn' => 'The Bulgarian lev has the fixed rate of 1.95583 per euro and is not entered.',
        'exists' => 'There is already a rate for this currency and date. Edit the existing one.',
        'rate_positive' => 'The rate must be greater than 0.',
        'rate_numeric' => 'The rate must be a number, e.g. 1.0850.',
    ],

    'attributes' => [
        'rate_date' => 'date',
        'currency' => 'currency',
        'rate' => 'rate',
    ],
];
