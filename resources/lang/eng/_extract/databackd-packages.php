<?php

// FRAGMENT of resources/lang/eng/databackd-packages.php - merge into "modules" (package flags of this module).
// Not a standalone lang file: copy the keys into the existing file of the host project.
return [
    'modules' => [
        'databackd_data' => [
            'name' => 'Data entry and import',
            'description' => 'Manual entry, bulk entry, import from Excel, CSV, Google Sheets and bank statements, accounts and dimensions.',
        ],
        'databackd_reports' => [
            'name' => 'Financial dashboard and reports',
            'description' => 'Financial dashboard, flexible reports, income statement, cash flow, counterparties, margins and annual statements.',
        ],
        'databackd_integrations' => [
            'name' => 'API and external sources',
            'description' => 'API tokens, REST endpoint and synchronised external sources.',
        ],
    ],
    'module_not_in_package' => 'The module ":module" is not included in your package. Contact the administrator to change the package.',
];
