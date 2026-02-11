<?php

// FRAGMENT of resources/lang/bg/databackd-packages.php - merge into "modules" (package flags of this module).
// Not a standalone lang file: copy the keys into the existing file of the host project.
return [
    'modules' => [
        'databackd_data' => [
            'name' => 'Въвеждане и импорт',
            'description' => 'Ръчно и множествено въвеждане, импорт от Excel, CSV, Google Sheets и банкови извлечения, сметки и разрези.',
        ],
        'databackd_reports' => [
            'name' => 'Финансово табло и справки',
            'description' => 'Финансово табло, гъвкави справки, отчет за приходите и разходите, парични потоци, контрагенти, маржове и годишен отчет.',
        ],
        'databackd_integrations' => [
            'name' => 'API и външни източници',
            'description' => 'API токени, REST интерфейс и синхронизирани външни източници.',
        ],
    ],
    'module_not_in_package' => 'Модулът „:module“ не е включен във Вашия пакет. Свържете се с администратора, за да смените пакета.',
];
