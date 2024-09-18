<?php

return [
    'menu' => 'Integrations',
    'title' => 'Integrations',
    'subtitle' => 'Connect Anagami Finance View to your sources of financial data — files, Google Sheets, the REST API, accounting program exports and bank statements.',

    'badges' => [
        'active' => 'Active',
        'locked' => 'Not in your package',
        'locked_hint' => 'The "Data" module is not included in your package. Ask the administrator to add it.',
        'soon' => 'Soon',
    ],

    'sections' => [
        'sources' => 'Data Sources',
        'sources_hint' => 'Choose how to bring in your data. Every source writes to the same financial model and appears in the import history.',
        'planned' => 'Planned Integrations',
        'planned_hint' => 'We are working on direct API connections to international CRM and accounting platforms — no manual file exports.',
    ],

    'cards' => [
        'excel' => [
            'title' => 'Excel',
            'desc' => 'Upload an .xlsx or .xls file, map the columns and preview the data before importing.',
            'action' => 'Import from Excel',
        ],
        'csv' => [
            'title' => 'CSV',
            'desc' => 'Import CSV with automatic delimiter and encoding detection (UTF-8 and Windows-1251).',
            'action' => 'Import from CSV',
        ],
        'google_sheets' => [
            'title' => 'Google Sheets',
            'desc' => 'Connect a shared spreadsheet and synchronize it in one click, without uploading files by hand.',
            'action' => 'Manage sources',
        ],
        'api' => [
            'title' => 'REST API',
            'desc' => 'Send records straight from your own system with a JSON request and an API token.',
            'action' => 'API access and documentation',
        ],
    ],

    'planned' => [
        'microinvest' => [
            'title' => 'Microinvest',
            'desc' => 'Trial balances and movements from Microinvest.',
        ],
        'ajur' => [
            'title' => 'Ajur',
            'desc' => 'Accounting entries and balances from Ajur.',
        ],
        'plus_minus' => [
            'title' => 'Plus Minus',
            'desc' => 'Import financial data from Plus Minus.',
        ],
        'business_navigator' => [
            'title' => 'Business Navigator',
            'desc' => 'Connection to Business Navigator for accounting data.',
        ],
        'salesforce' => [
            'title' => 'Salesforce',
            'desc' => 'Revenue and opportunities from Salesforce CRM.',
        ],
        'quickbooks' => [
            'title' => 'QuickBooks',
            'desc' => 'Automatic import of transactions from QuickBooks.',
        ],
        'xero' => [
            'title' => 'Xero',
            'desc' => 'Automatic import of transactions from Xero.',
        ],
        'bank_statements' => [
            'title' => 'Bank Statements',
            'desc' => 'Import of bank statements in MT940 and CAMT formats.',
        ],
    ],

    'sync' => [
        'title' => 'Sync from Existing Modules',
        'desc' => 'Transfers the invoices and expenses already entered in your account to the financial model for analysis. Running it again does not duplicate records — it updates changed documents and removes cancelled ones.',
        'button' => 'Transfer from invoices and expenses',
        'running' => 'Transferring…',
        'done' => 'Synchronization finished.',
        'failed' => 'Synchronization failed.',
        'preview' => 'Preview before transfer',
    ],

    'api' => [
        'title' => 'API Access',
        'subtitle' => 'Tokens let external systems write financial data to your company. Keep them as secret as a password.',
        'invalid_json' => 'Invalid JSON in the request body.',
        'no_records' => 'No records were submitted. Send a JSON array of records or an object {"entries": [...]}.',
        'too_many' => 'The maximum is :max records per request.',
    ],

    'tokens' => [
        'title' => 'API Tokens',
        'name' => 'Token name',
        'name_placeholder' => 'e.g. Accounting software',
        'name_help' => 'A name that helps you recognize the system that uses the token.',
        'new' => 'New token',
        'generate' => 'Generate token',
        'token' => 'Token',
        'created_at' => 'Created',
        'last_used' => 'Last used',
        'never_used' => 'Never used',
        'status' => 'Status',
        'active' => 'Active',
        'revoked' => 'Revoked',
        'revoke' => 'Revoke',
        'empty' => 'There are no tokens yet. Create the first token to start sending data through the API.',
        'admin_only' => 'Only an administrator can create and revoke tokens.',
        'modal_title' => 'Token Created',
        'modal_warning' => 'Save it now — it will not be shown again.',
        'modal_hint' => 'Use it in the Authorization: Bearer <token> header.',
        'copy' => 'Copy',
        'failed' => 'The token could not be created. Try again.',
        'copied' => 'Copied',
        'close' => 'Done',
        'revoke_title' => 'Revoke this token?',
        'revoke_text' => 'Systems using it will lose access immediately. This cannot be undone.',
        'revoke_confirm' => 'Yes, revoke',
        'cancel' => 'Cancel',
    ],

    'docs' => [
        'title' => 'Documentation',
        'endpoint' => 'Endpoint',
        'endpoint_text' => 'Send a POST request with a JSON body. Records are added to the financial model with the source "API" and appear in the import history.',
        'headers' => 'Headers',
        'header_auth' => 'Authorization: Bearer <token> — required',
        'header_content' => 'Content-Type: application/json',
        'header_accept' => 'Accept: application/json — recommended',
        'idempotency_text' => 'Idempotency-Key header: "<unique key, e.g. a UUID>" (recommended) - a repeated request with the same key, the same token and the same body within 24 hours returns the first response without writing the data again. The key applies only to the token it was sent with.',
        'body' => 'Request body',
        'body_text' => 'A JSON array of records (or an object {"entries": [...]}) whose keys are the field ids from the table below. Up to :max records per request.',
        'options' => 'Parameters',
        'option_create' => 'create_missing_dimensions=1 — automatically creates missing regions, segments, product groups and cost centers (by default they are rejected as an error).',
        'option_create_body' => 'The option can also be sent in the body when it is an object: {"entries": [...], "create_missing_dimensions": true}. At most :dimensions new dimension values are created in one request.',
        'curl' => 'Example request (curl)',
        'token_placeholder' => '<YOUR_TOKEN>',
        'response' => 'Response',
        'response_text' => 'Valid records are saved, invalid ones are returned with an error. The row number is the position in the submitted array, starting from 1.',
        'codes' => 'Response codes',
        'code_200' => 'all records were saved',
        'code_207' => 'one or more records were rejected (status is partial or failed) — see errors',
        'code_400' => 'invalid Idempotency-Key header',
        'code_401' => 'missing, invalid or revoked token',
        'code_403' => 'inactive company, the package does not include integrations or the request is not made over HTTPS',
        'code_405' => 'the method is not POST (the response has an Allow: POST header)',
        'code_409' => 'a request with the same Idempotency-Key is still being processed',
        'code_413' => 'the body is larger than :bytes bytes',
        'code_415' => 'Content-Type: application/json is missing',
        'code_422' => 'invalid JSON, no records, more than :max records, more than :dimensions new dimension values in one request (with create_missing_dimensions) or an Idempotency-Key used with a different body or query string',
        'code_429' => 'more than :per_minute requests per minute or :per_hour records per hour for one token, :failed_ip failed sign-in attempts from one IP address per minute, or more than :per_ip requests per minute from one IP address in total - wait the seconds given in Retry-After',
        'code_500' => 'unexpected error — nothing was saved, the request can be retried',
        'fields' => 'Record fields',
        'fields_limits' => 'Every field is a string or a number; strings are up to 2000 characters. The counterparty and the description are stored up to 191 characters, the document number up to 60.',
        'field_id' => 'Key',
        'field_name' => 'Field',
        'field_required' => 'Required',
        'field_hint' => 'Hint',
        'yes' => 'Yes',
        'no' => 'No',
        'copy' => 'Copy',
    ],

    'messages' => [
        'token_created' => 'The token was created.',
        'token_revoked' => 'The token was revoked.',
    ],
];
