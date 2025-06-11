<?php

// AFV (S8 / B5): setup wizard for a new company (first-time setup).
return [
    'menu' => 'Initial Setup',
    'menu_progress' => 'Initial Setup :done/:total',
    'title' => 'Initial Setup',
    'subtitle' => 'Five short steps and your financial dashboard is ready. You can change everything later.',
    'step_of' => 'Step :current of :total',
    'percent_done' => ':percent% done',
    'steps_handled' => ':done of :total steps completed',

    'steps' => [
        'company' => [
            'short' => 'Company',
            'title' => 'Company Details',
        ],
        'entities' => [
            'short' => 'Legal entities',
            'title' => 'Legal Entities',
        ],
        'chart' => [
            'short' => 'Chart of accounts',
            'title' => 'Chart of Accounts',
        ],
        'import' => [
            'short' => 'First import',
            'title' => 'First Import',
        ],
        'dashboard' => [
            'short' => 'Dashboard',
            'title' => 'Your Dashboard is Ready',
        ],
    ],

    'status' => [
        'done' => 'Done',
        'skipped' => 'Skipped',
        'pending' => 'Not started',
    ],

    'actions' => [
        'back' => 'Back',
        'save_continue' => 'Save and continue',
        'apply_continue' => 'Apply and continue',
        'continue' => 'Continue',
        'skip' => 'Skip this step',
        'finish' => 'Finish setup',
        'finish_open' => 'Finish and open the dashboard',
        'dismiss' => 'Hide the setup guide',
        'restore' => 'Show the setup guide again',
        'add_subsidiary' => 'Add another subsidiary',
        'remove' => 'Remove',
        'show_accounts' => 'Show the accounts',
    ],

    'state' => [
        'completed' => 'The setup was completed on :date. You can still go through the steps and change anything.',
        'dismissed' => 'The setup guide is hidden from the menu and the dashboard.',
    ],

    'company' => [
        'intro' => 'These details appear on your invoices and reports. Check them and correct what is needed.',
        'name' => 'Company name',
        'name_placeholder' => 'e.g. Demo Trade Ltd',
        'tax_number' => 'Company ID (EIK) / VAT number',
        'tax_number_placeholder' => 'e.g. 200000101 or BG200000101',
        'tax_number_help' => 'Shown on invoices next to the address.',
        'tax_name' => 'EIK / VAT',
        'address' => 'Address',
        'address_placeholder' => 'Street, number, postcode and city',
        'currency' => 'Currency',
        'currency_help' => 'The financial analysis works in euro (EUR). The currency of the company is changed in the company settings.',
        'open_settings' => 'Open the company settings',
    ],

    'entities' => [
        'intro' => 'A legal entity is a company of your group. Even if you have a single company, add it as the main legal entity - data is recorded and reported per entity, and subsidiaries can be consolidated later.',
        'existing' => 'Legal entities in your account',
        'columns' => [
            'name' => 'Name',
            'eik' => 'Company ID (EIK)',
            'country' => 'Country',
            'currency' => 'Currency',
            'parent' => 'Subsidiary of',
            'share' => 'Share',
        ],
        'none' => 'No legal entities yet',
        'none_text' => 'Add your company below. It becomes the main legal entity.',
        'main_title' => 'Main Legal Entity',
        'main_help' => 'Usually the company itself. It is already filled in from your company details.',
        'subsidiaries_title' => 'Subsidiaries (Optional)',
        'subsidiaries_help' => 'Companies that you own a share of. They are added under the main legal entity ":name".',
        'subsidiaries_help_new' => 'Companies that you own a share of. They are added under the main legal entity.',
        'name' => 'Name',
        'eik' => 'Company ID (EIK)',
        'country' => 'Country (2 letters)',
        'currency' => 'Currency (3 letters)',
        'share' => 'Share, %',
        'manage' => 'Manage the legal entities',
        'limit' => 'Up to :count subsidiaries at a time; more can be added later in the legal entities screen.',
    ],

    'chart' => [
        'intro' => 'The chart of accounts is the list of accounts where revenue, expenses, cash, receivables and payables are recorded. Pick the set that fits your business. You can change it at any time in the accounts screen.',
        'current' => 'Now: :active active accounts of :total; :used of them already have records.',
        'safe' => 'Nothing is deleted: the accounts that are not needed are only hidden from data entry. An account that already has records always stays active.',
        'nss' => [
            'title' => 'Standard (Bulgarian accounting standards)',
            'text' => 'All 29 accounts: capital, fixed assets, inventories, receivables, payables, taxes, cash, expenses and revenue. For companies with fixed assets, inventories, loans or several types of revenue.',
            'count' => ':count accounts',
        ],
        'micro' => [
            'title' => 'Simplified for Micro Companies',
            'text' => 'The most needed 16 accounts: capital, cash, customers, suppliers and personnel, VAT, revenue and expenses. The other standard accounts are hidden - fixed assets, inventories, loans, interest, depreciation.',
            'count' => ':count accounts',
        ],
        'selected' => 'Selected',
        'current_badge' => 'Applied now',
        'accounts_list' => 'Accounts in this set',
        'open_accounts' => 'Open the accounts screen',
    ],

    'import' => [
        'intro' => 'Load your first data - revenue, expenses and balances - so that the dashboard shows real numbers. You can also do this later.',
        'detected' => 'You already have :entries records from :imports imports.',
        'excel_title' => 'Upload an Excel / CSV file',
        'excel_text' => 'Download the template, fill it in with your data and upload it. Every row is checked before it is imported, and an import can be undone.',
        'open_import' => 'Open the import wizard',
        'download_xlsx' => 'Download the Excel template',
        'download_csv' => 'Download the CSV template',
        'sheets_title' => 'Connect Google Sheets',
        'sheets_text' => 'Paste the link of a shared Google sheet. The data can be refreshed whenever the sheet changes.',
        'open_sheets' => 'Connect a Google sheet',
        'sheets_not_in_package' => 'Connecting Google Sheets is not included in your package.',
        'later_title' => 'I will do it later',
        'later_text' => 'Skip this step. The dashboard stays empty until the first data is loaded.',
        'skip_later' => 'I will do it later',
        'unavailable' => 'Not available',
    ],

    'dashboard' => [
        'intro' => 'Here is what is set up. You can change everything later from the Analysis section of the menu.',
        'summary' => 'Summary',
        'company' => 'Company',
        'entities' => 'Legal entities',
        'entities_value' => ':count legal entities',
        'chart' => 'Chart of accounts',
        'chart_value' => ':template - :active active accounts',
        'chart_default' => 'Standard (not changed)',
        'import' => 'Data',
        'import_value' => ':entries records, :imports imports',
        'no_data' => 'There is no data yet, so the dashboard will be empty. Load your first data from the Import screen at any time.',
        'ready' => 'All set. The dashboard shows your revenue, expenses, profit and cash from the data you load.',
        'not_available' => 'The financial dashboard is not part of your package.',
    ],

    'templates' => [
        'nss' => 'Standard (NSS)',
        'micro' => 'Simplified for micro companies',
    ],

    'banner' => [
        'title' => 'Finish Setting Up Your Account',
        'text' => ':done of :total steps are done. Next: :next.',
        'continue' => 'Continue the setup',
        'hide' => 'Hide',
    ],

    'messages' => [
        'company_saved' => 'The company details are saved.',
        'entities_saved' => 'Added legal entities: :count.',
        'step_done' => 'The step is marked as done.',
        'step_skipped' => 'The step is skipped. You can come back to it at any time.',
        'chart_applied' => [
            'nss' => 'The standard chart of accounts is applied: :active accounts are active.',
            'micro' => 'The simplified chart of accounts is applied: :active active accounts, :inactive hidden.',
        ],
        'chart_kept' => 'Accounts outside the set that stay active because they already have records: :count (:codes).',
        'completed' => 'The initial setup is complete.',
        'dismissed' => 'The setup guide is hidden. You can show it again from the setup page.',
        'restored' => 'The setup guide is visible again.',
    ],

    'validation' => [
        'main_required' => 'Enter the name of the main legal entity, or skip this step.',
        'name_taken' => 'A legal entity with this name already exists.',
        'name_duplicate' => 'This name is entered more than once.',
        'name_same_as_main' => 'A subsidiary cannot have the same name as the main legal entity.',
        'template_required' => 'Choose a chart of accounts.',
    ],

    'attributes' => [
        'company_name' => 'company name',
        'tax_number' => 'company ID / VAT number',
        'address' => 'address',
        'template' => 'chart of accounts',
        'status' => 'status',
        'main_name' => 'name of the main legal entity',
        'main_eik' => 'company ID (EIK)',
        'main_country' => 'country',
        'main_currency' => 'currency',
        'sub_name' => 'subsidiaries',
        'sub_name.*' => 'name of the subsidiary',
        'sub_eik.*' => 'company ID (EIK)',
        'sub_country.*' => 'country',
        'sub_currency.*' => 'currency',
        'sub_pct.*' => 'share',
    ],
];
