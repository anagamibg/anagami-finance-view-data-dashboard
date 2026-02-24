# Anagami Finance View (AFV) – модул „Данни и финансово табло“

Самостоятелно изнесен модул от платформата **Anagami Finance View (AFV)** – multi-tenant SaaS за управление, обработка и анализ на финансовите данни на малки и средни предприятия. Модулът обхваща въвеждането и импорта на финансови данни (счетоводни записи), настройките към тях (сметкоплан, разрези, юридически лица, валутни курсове), интеграциите (API, Google Sheets) и началното **финансово табло** с ключови показатели и графики.

> **Частно / вътрешно ползване.** Репото е частно и съдържа фрагмент от собствения код на Anagami. То не е самостоятелно приложение – файловете се копират върху съществуващ AFV (Laravel) проект със запазени относителни пътища. Не е предназначено за публикуване или разпространение.

Кодовите идентификатори са запазени от оригиналния проект и **не са преименувани**: namespaces `…\Databackd\…`, папки `routes/databackd/`, `resources/views/databackd/`, lang файлове `databackd-*.php`, помощни функции `databackd_*()`, модулни флагове `databackd_data`, `databackd_reports`, `databackd_integrations` и т.н.

---

## 1. Функционалности

Описанието следва реалния код в репото.

### 1.1. Данни (`databackd_data`)

| Функция | Какво прави | Основни файлове |
|---|---|---|
| **Записи (fin_entries)** | Списък (Yajra DataTable) с филтри, ръчно добавяне/редакция/изтриване, бързи масови действия, експорт в Excel (със защита срещу формули в клетките). Записите са два вида – `flow` (приход/разход) и `balance` (салдо) – в сума, валута и сума в базова валута (`amount_base`). | `FinEntryController`, `FinEntriesDataTable`, `FinEntriesExport`, `StoreFinEntry`/`UpdateFinEntry` |
| **Множествено въвеждане** | Таблично въвеждане и зареждане на много записи наведнъж. | `FinEntryBulkController`, `views/data/bulk` |
| **Сметкоплан** | Сметки по типове (актив, пасив, капитал, приход, разход…), активиране/деактивиране. Опростен сметкоплан по българските НСС (класове 1–7) се създава автоматично за всяка нова компания. | `FinAccountController`, `ChartOfAccounts` |
| **Разрези (dimensions)** | Региони, клиентски сегменти, продуктови групи, разходни центрове. | `FinDimensionController` |
| **Юридически лица** | Дървовидна структура (майка – дъщерно дружество, процент собственост) – основа за консолидация. | `FinEntityController`, `views/data/entities` |
| **Импорт от Excel/CSV** | Съветник в 3 стъпки: качване, съпоставяне на колони, проверка (preview на първите 10 реда), стартиране (опашка – `ImportFinEntriesJob`) с прогрес и резултат. Шаблон за изтегляне (XLSX/CSV). | `FinImportController`, `FinEntryImporter`, `ImportFinEntriesJob` |
| **Шаблони за счетоводни програми** | Разпознаване на колони и подготвени примерни файлове за Microinvest, Ajur, Плюс-Минус и Бизнес навигатор (оборотна ведомост и дневник). | `ImportTemplates`, `public/databackd/templates/import-templates` |
| **История на импортите** | Партиди (`fin_import_batches`) със статус (pending/processing/completed/partial/failed/undone), експорт на грешките и **отмяна** на целия импорт. | `FinImportBatchController`, `FinImportBatchesDataTable` |
| **Импорт от Google Sheets / CSV по URL** | Изтегляне от публичен адрес (само https към Google Sheets) със защита от SSRF (блокиране на частни/резервирани IP адреси, ограничения за пренасочвания, размер, редове), записани източници и повторна синхронизация. | `FinDataSourceController`, `GoogleSheetsReader`, `FinDataSource` |
| **REST API** | `POST /api/databackd/fin-entries` с Bearer токен (SHA-256 хеш в базата), ограничения за размер, брой записи, нови разрези и честота (на токен и на IP при грешна автентикация), изискване за HTTPS, одит. Управление на токените от страница „Интеграции“. | `FinEntryApiController`, `FinApiTokenAuth`, `FinApiGate`, `FinApiAudit`, `config/databackd-api.php` |
| **Трансфер от фактури и разходи** | Прехвърляне на платени фактури и одобрени разходи от ядрото на ERP-то във `fin_entries`. | `FinSyncController`, `InvoiceExpenseSync` |

### 1.2. Допълнения към данните („gaps“)

| Функция | Какво прави | Основни файлове |
|---|---|---|
| **Импорт на банкови извлечения** | Формати **MT940** и **CAMT.053**; откриване на формата, проверка на IBAN, преглед преди запис, правила „контрагент → сметка“ (`fin_counterparty_mappings`), списък на импортираните извлечения с отмяна, примерни файлове. | `BankImportController`, `BankStatementController`, `CounterpartyMappingController`, `Mt940Parser`, `Camt053Parser`, `CounterpartyMatcher`, `Iban` |
| **Валутни курсове** | Курсове по дата (ръчно въвеждане + изтегляне от **ЕЦБ**), графика, използвани при `amount_base`; BGN е фиксиран спрямо EUR. | `FxRateController`, `FxRateService`, `FinFxRate` |
| **Помощник за нова компания (onboarding)** | Съветник в 5 стъпки (компания, юридически лица, сметкоплан по шаблон `nss`/`micro`, импорт, табло), банер с напредъка, пропускане/отлагане. | `OnboardingController`, `OnboardingService`, `FinOnboarding` |
| **Аларми при прагове** | Правила от 4 типа (`ratio_range`, `budget_deviation`, `cash_below`, `receivables_above`), 3 нива на важност, канали „в приложението“ и e-mail, история с потвърждаване, ръчна проверка и команда `databackd:check-alerts` (`--dry-run`, `--company=`). | `AlertRuleController`, `AlertHistoryController`, `AlertChecker`, `CheckAlerts`, `FinAlertMail`, `FinAlertNotification` |

### 1.3. Финансово табло (`databackd_reports`)

Страница `/account/databackd/reports/dashboard` със:

- **7 KPI карти** – приходи, разходи, печалба, маржин, парични средства, вземания, задължения – със сравнение с предходен период (процент или процентни пунктове) и посока „добре/зле“;
- **7 графики** – по периоди, по продуктови групи, по региони, по клиентски сегменти (по периоди), структура на разходите, топ контрагенти (топ 10), парични потоци (нетен поток и салдо);
- общи филтри (период, периодичност, юридически лица, разрези, клиент, сравнение), изчисляване директно от `fin_entries`, баннер на onboarding съветника и празно състояние с връзка към импорта.

Основни файлове: `FinDashboardController`, `DashboardMetrics`, `PeriodBuckets`, `ReportFilters`, `views/databackd/reports/dashboard`, `resources/js/databackd-charts.js`.

---

## 2. Структура на репото

Оригиналните относителни пътища са запазени (`app/…`, `routes/…`, `resources/…`, `database/…`, `config/…`, `public/…`).

| Категория | Файлове | Къде |
|---|---:|---|
| Контролери | 20 | `app/Http/Controllers/Databackd/{Data,Data/Api,Gaps/*,Reports}` |
| Модели | 13 | `app/Models/Databackd/` |
| Услуги (services) | 23 | `app/Services/Databackd/{Data,Gaps/*,Reports}` + `ChartOfAccounts`, `FinancialDataService` |
| Middleware | 4 | `app/Http/Middleware/Databackd/` |
| Form requests | 15 | `app/Http/Requests/Databackd/{Data,Gaps}` |
| DataTables и Exports | 5 | `app/DataTables/Databackd/`, `app/Exports/Databackd/` |
| Job, Mail, Notification, Console | 4 | `app/{Jobs,Mail,Notifications,Console/Commands}/Databackd/` |
| Routes | 15 | `routes/databackd/` (14 уеб файла + `api/fin-entries.php`) |
| Миграции | 8 | `database/migrations/` |
| Seeders (демо) | 6 | `database/seeders/Databackd/Demo/` |
| Blade изгледи | 58 | `resources/views/databackd/{data,gaps,reports}` |
| Менюта | 13 | `resources/views/databackd/menu/` |
| Lang – английски | 17 | `resources/lang/eng/databackd-*.php` |
| Lang – български | 17 | `resources/lang/bg/databackd-*.php` |
| Lang – фрагменти от общи файлове | 6 | `resources/lang/{eng,bg}/_extract/` |
| SCSS и JS | 5 | `resources/scss/databackd/` (4), `resources/js/databackd-charts.js` |
| Шаблони за импорт и примерни извлечения | 12 | `public/databackd/templates/` |
| Конфигурация | 1 | `config/databackd-api.php` |
| Други | 1 | `.gitignore` |
| README | 1 | `README.md` |
| **Общо** | **244** | |

Разбивка на изгледите: записи 4, множествено въвеждане 1, сметки 3, разрези 2, юридически лица 3, импорт 6, история на импортите 5, източници 5, интеграции 2, трансфер 1, банков импорт 8, валутни курсове 3, onboarding 7, аларми 6, табло 1 + общият партиал `reports/_filters`.

### Маршрути (всички с префикс `/account`, освен API-то)

| Файл | Път | Флаг на модула |
|---|---|---|
| `data-entries.php`, `data-bulk.php`, `data-accounts.php`, `data-dimensions.php` (+ юридически лица) | `databackd/data/{entries,bulk,accounts,dimensions,entities}` | `databackd_data` |
| `data-import.php`, `data-batches.php`, `data-sync.php` | `databackd/data/{import,batches,sync}` | `databackd_data` |
| `data-sources.php`, `data-integrations.php` | `databackd/data/{sources,integrations}` | `databackd_integrations` |
| `gaps-bank-import.php`, `gaps-fx-rates.php`, `gaps-onboarding.php` | `databackd/gaps/{bank-import,fx-rates,onboarding}` | `databackd_data` |
| `gaps-alerts.php` | `databackd/gaps/alerts` | `databackd_analytics` |
| `reports-dashboard.php` | `databackd/reports/dashboard` | `databackd_reports` |
| `api/fin-entries.php` | `POST /api/databackd/fin-entries` (без `/account`, токен автентикация) | `databackd_integrations` (проверява се след токена) |

---

## 3. Зависимости от останалата система (НЕ са включени)

Модулът разчита на следното в хост проекта (AFV на Laravel 12). Тези файлове са общи за всички AFV модули и затова не са копирани:

**Ядро на приложението**
- Базови класове и помощници: `App\Http\Controllers\AccountBaseController`, `App\Http\Requests\CoreRequest`, `App\Models\BaseModel`, `App\DataTables\BaseDataTable`, `App\Helper\Reply`, `App\Helper\Common`, трейт `App\Traits\HasCompany`, `App\Scopes\CompanyScope`, `App\Notifications\BaseNotification`.
- Модели на ядрото: `Company`, `User`, `Currency`, `CompanyAddress`, `SmtpSetting`, `Invoice`, `Payment`, `Expense`, `CreditNotes`; `App\Exceptions\Handler`.
- Глобалните функции `company()` и `user()`.

**Помощни функции и конфигурация на AFV**
- `app/Helper/databackd.php` и `app/Helper/databackd_modules.php` (функциите `databackd_module()`, `databackd_company_module()`, `databackd_menu_module()` и каталога на модулите; зареждат се от composer `autoload.files`; `routes/databackd/00-modules.php` само ги зарежда повторно за случая на кеширани маршрути).
- `config/databackd.php`; `config/cors.php`, `config/security-headers.php`.

**Регистрация в хост проекта**
- `app/Providers/RouteServiceProvider.php` – автоматично зарежда всеки файл от `routes/databackd/*.php` (с `web`, `auth` … и префикс `account`) и `routes/databackd/api/*.php` (група `api`, префикс `api`). Нови регистрации на маршрути не са нужни.
- `app/Providers/AppServiceProvider.php` – (а) `Company::created` извиква `ChartOfAccounts::ensureFor($company->id)`; (б) View composer на `sections.menu` събира всички `resources/views/databackd/menu/*.blade.php` и ги филтрира по `databackd_menu_module()`.
- `app/Http/Kernel.php` – `\App\Http\Middleware\Databackd\FinApiGate::class` трябва да е в глобалния стек (преди парсиране на тялото на заявката).
- `app/Console/Kernel.php` – планиране на командата: `$schedule->command('databackd:check-alerts')->hourly()->withoutOverlapping();`.
- Observers няма – за този модул не се регистрират такива.

**Общи изгледи и ресурси**
- Blade компоненти `x-dbk.page-header`, `x-dbk.kpi`, `x-dbk.card`, `x-dbk.empty`, `x-dbk.chart` (`resources/views/components/dbk/`), както и стандартните `x-forms.*`, `x-sub-menu-item`, `layouts.app`, `sections.datatable_js/css`, `sections.menu`.
- SCSS на дизайн системата (`databackd/_tokens`, `_components`, `_sidebar`, `_layout` и др.) и `resources/scss/main.scss`, който трябва да импортира включените тук партиали:
  ```scss
  @import "databackd/dashboard";
  @import "databackd/data";
  @import "databackd/import";
  @import "databackd/bank-fx";
  ```
- `layouts/app.blade.php` зарежда Chart.js и `public/js/databackd-charts.js`; `webpack.mix.js` компилира `resources/js/databackd-charts.js` → `public/js/databackd-charts.js`.

**Общи lang файлове** (само релевантните ключове са извадени като фрагменти в `resources/lang/{eng,bg}/_extract/`):
- `databackd-ui.php` – `menu_groups.overview`, `menu_groups.data`, `menu_groups.data_settings`;
- `databackd-misc.php` – `close`, `source_rate_limited`, `import.*`, `sheets.*`;
- `databackd-packages.php` – описанията на модулите `databackd_data`, `databackd_reports`, `databackd_integrations` и `module_not_in_package`.

**Включен като зависимост на таблото:** `ReportFilters`, `resources/views/databackd/reports/_filters.blade.php` и `databackd-reports-common.php` са общи с модула „Справки“, но са нужни на таблото и затова са копирани. Подобно `NeutralizesFormulas` (споделен от всички Excel експорти) и `ColumnWhitelist` (DataTables).

**Таблици/модули извън обхвата, към които има препратки**
- Аларми: правилата от тип `ratio_range` и `budget_deviation` използват `FinRatio`, `FinBudget`, `FinBudgetLine`, `RatioCalculator` и `BudgetActuals` от модулите „Коефициенти“ и „Бюджети“; маршрутите на алармите са зад флага `databackd_analytics`. Типовете `cash_below` и `receivables_above` работят само върху данните от този модул.
- Onboarding: при определяне на използваните сметки брои и `FinBudgetLine`.
- Миграцията `2026_10_28_100000_create_databackd_gaps_tables.php` е включена цяла – освен таблиците на този модул (`fin_bank_statements`, `fin_counterparty_mappings`, `fin_fx_rates`, `fin_alert_rules`, `fin_alert_events`, `fin_onboarding`) тя разширява `fin_entries.source` със стойност `bank_statement` и добавя `products.cost_price` (колоната е за справката за маржове, която не е част от този модул).
- Seeder `46ProductCostAndFxDemoSeeder` попълва и себестойност на продукти (за маржовете) наред с валутните курсове.

---

## 4. Изисквания

Проверено в `composer.json` на източника:

- **PHP ≥ 8.2** (`"php": "^8.2"`)
- **Laravel 12** (`laravel/framework ^12.0`), `laravel/sanctum ^4.0`
- `maatwebsite/excel ^3.1.41` (импорт/експорт; ползва се и `PhpOffice\PhpSpreadsheet`)
- `yajra/laravel-datatables-oracle`, `-html`, `-buttons` `^12.0`
- **MariaDB / MySQL** (миграциите ползват стандартни типове и `enum`; проверка на `ENUM` колоната `fin_entries.source` в миграцията на „gaps“)
- Работеща опашка (queue) за `ImportFinEntriesJob`, scheduler за `databackd:check-alerts`
- Достъп до `https://www.ecb.europa.eu` (курсове) и `https://docs.google.com` (Google Sheets) от сървъра – само за съответните функции
- Multi-tenant ядро с таблиците `companies`, `packages`, `modules`, `module_settings`

---

## 5. Интеграция

1. **Копиране.** Копирайте съдържанието на репото върху корена на Laravel проекта (запазват се пътищата). Не презаписвайте общите файлове на проекта – тук те не присъстват. Файловете от `_extract/` **не се копират като такива**, а само се сливат ръчно (виж стъпка 4).
2. **Миграции** (в този ред, по име на файла):
   ```bash
   php artisan migrate
   ```
   Включени са `2026_10_27_100000` … `100400` (юридически лица, сметки, разрези, партиди, записи), `200000`/`200100` (източници и API токени) и `2026_10_28_100000` (банкови извлечения, курсове, аларми, onboarding). Миграцията, която регистрира модулните флагове (`2026_10_28_090000_add_databackd_modules.php`), е обща за всички AFV модули и не е включена – вижте раздел 6.
3. **Маршрути, меню, observers.** Не изискват ръчна регистрация, ако `RouteServiceProvider` и `AppServiceProvider` вече съдържат автоматичното зареждане от раздел 3. Необходим е `FinApiGate` в `app/Http/Kernel.php` и планираната команда в `app/Console/Kernel.php`.
4. **Lang.** Файловете `databackd-*.php` в `eng/` и `bg/` се копират директно. Фрагментите от `resources/lang/{eng,bg}/_extract/` се сливат в съществуващите `databackd-ui.php`, `databackd-misc.php` и `databackd-packages.php`. Българските файлове могат да са в `.gitignore` на хост проекта (напр. `resources/lang/*` без `eng/`) – копирайте ги на сървъра.
5. **Ресурси.** Добавете `@import`-ите от раздел 3 в `main.scss`, после `npm run dev` / `npm run prod`.
6. **Конфигурация.** `config/databackd-api.php` – лимити на REST API-то; променлива `DATABACKD_API_REQUIRE_HTTPS` (по подразбиране `true`; `false` само когато TLS е зад прокси без `X-Forwarded-Proto`).
7. **Демо данни (по желание).** Seeders-ите разчитат на базовия `DatabackdDemoSeeder` (компания, потребители, клиенти, продукти) и на командата `databackd:demo`, които не са включени. Демо API токенът в `41DataDemoSeeder` се извежда от `APP_KEY` (`DataDemoSeeder::demoToken()`), а не е записан в кода.
8. **Кешове** след внедряване: `php artisan config:cache && php artisan route:cache && php artisan view:clear`.

---

## 6. Модулни флагове на пакетите

Достъпът се управлява чрез пакетите на SaaS платформата (`packages.module_in_package`) и `module_settings` на компанията, проверявани от `databackd_module()` / `RequireModule` (за API-то – `RequireCompanyModule`, след като токенът е определил компанията). Без флага отговорът е 403, а менюто не се показва.

| Флаг | Роли | Какво отключва в този модул |
|---|---|---|
| `databackd_data` | admin, employee | записи, множествено въвеждане, сметки, разрези, юридически лица, импорт, история, трансфер, банков импорт, валутни курсове, onboarding |
| `databackd_integrations` | admin, employee | Google Sheets/CSV източници, страница „Интеграции“ и токени, REST API |
| `databackd_reports` | admin, employee | финансово табло |
| `databackd_analytics` | admin, employee | аларми (в този модул); флагът принадлежи на „Коефициенти и консолидация“ |

Съответствие меню → флаг (`databackd_menu_module()`): `05-reports-dashboard` → `databackd_reports`; `06-gaps-onboarding`, `10`–`12`, `14`, `16`–`19` → `databackd_data`; `13-data-sources`, `15-data-integrations` → `databackd_integrations`; `35-gaps-alerts` → `databackd_analytics`.

Миграцията `add_databackd_modules` създава редове в `modules` (`databackd_data` – „Data entry and import“, `databackd_reports` – „Financial dashboard and reports“, `databackd_analytics`, `databackd_planning`, `databackd_teams`, `databackd_integrations` – „API and external sources“), добавя ги към всички съществуващи пакети и създава `module_settings` за съществуващите компании. Тя трябва да е изпълнена в хост проекта, преди модулът да се ползва.

---

## 7. Бележки

- Мулти-тенантност: всички модели ползват `HasCompany`/`CompanyScope`; `RequireModule` отказва достъп (403) на потребители без компания.
- Сигурност на импортите: защита от формули в Excel експортите, лимити за листове/клетки/редове, SSRF защита при изтегляне по URL, токените се пазят само като SHA-256 хеш.
- Репото не съдържа `vendor`, `node_modules`, `.env`, ключове, пароли, адреси на сървъри или вътрешна документация (`docs/`).
- Частно хранилище за вътрешно ползване на Anagami: не го публикувайте и не го споделяйте извън организацията.
