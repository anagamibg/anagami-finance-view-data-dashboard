<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// AFV (S8): tables for the features missing from the project proposal
// AFV (S10): every step is guarded (hasTable / hasColumn / ENUM check), so the migration can be re-run safely.
//  B1 bank statements (MT940 / CAMT.053) + counterparty -> account mapping, B2 product cost, B4 alerts,
//  B5 onboarding, B6 exchange rates.
return new class extends Migration {

    public function up()
    {
        // B2: cost of a product (gross margin report)
        if (!Schema::hasColumn('products', 'cost_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('cost_price', 15, 2)->nullable()->after('price');
            });
        }

        // B1: entries created from a bank statement
        // AFV (S10): re-runnable - skip the ALTER when the ENUM already has 'bank_statement'
        $sourceColumn = collect(Schema::getColumns('fin_entries'))->firstWhere('name', 'source');

        if (!$sourceColumn || !str_contains((string)($sourceColumn['type'] ?? ''), "'bank_statement'")) {
            DB::statement("ALTER TABLE fin_entries MODIFY source ENUM('manual','excel','google_sheets','csv','api','invoice','expense','demo','bank_statement') NOT NULL DEFAULT 'manual'");
        }

        // B1: header of an imported bank statement (one row per statement / account)
        if (!Schema::hasTable('fin_bank_statements')) {
            Schema::create('fin_bank_statements', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
                $table->unsignedBigInteger('import_batch_id')->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('format', 20);                       // mt940 | camt053
                $table->string('account_iban', 40)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->string('statement_no', 60)->nullable();
                $table->date('date_from')->nullable();
                $table->date('date_to')->nullable();
                $table->decimal('opening_balance', 15, 2)->nullable();
                $table->decimal('closing_balance', 15, 2)->nullable();
                $table->unsignedInteger('transactions_count')->default(0);
                $table->string('file_name')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'date_to']);
                $table->index('import_batch_id');
            });
        }

        // B1: which account a bank counterparty (name or IBAN fragment) is booked to
        if (!Schema::hasTable('fin_counterparty_mappings')) {
            Schema::create('fin_counterparty_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
                $table->string('pattern');                          // counterparty name / IBAN / text fragment (case-insensitive)
                $table->string('match_type', 20)->default('contains'); // contains | exact
                $table->string('direction', 10)->default('any');    // credit (money in) | debit (money out) | any
                $table->unsignedBigInteger('account_id');           // fin_accounts.id the transaction is booked to
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('counterparty_name')->nullable();    // normalized name to store on the entry
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'direction']);
            });
        }

        // B6: exchange rates by date (units of `currency` for 1 unit of `base_currency`, ECB convention)
        if (!Schema::hasTable('fin_fx_rates')) {
            Schema::create('fin_fx_rates', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
                $table->date('rate_date');
                $table->string('base_currency', 3)->default('EUR');
                $table->string('currency', 3);
                $table->decimal('rate', 18, 8);
                $table->string('source', 20)->default('manual');    // manual | ecb
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'rate_date', 'base_currency', 'currency'], 'fin_fx_rates_unique');
                $table->index(['company_id', 'currency', 'rate_date']);
            });
        }

        // B4: threshold rules
        if (!Schema::hasTable('fin_alert_rules')) {
            Schema::create('fin_alert_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
                $table->string('name');
                $table->string('type', 40);                         // ratio_range | budget_deviation | cash_below | receivables_above
                $table->unsignedBigInteger('entity_id')->nullable(); // null = all legal entities
                $table->json('params')->nullable();                 // ratio_id, min, max | budget_id, pct | amount ...
                $table->string('severity', 10)->default('warning'); // info | warning | critical
                $table->boolean('notify_app')->default(true);
                $table->boolean('notify_email')->default(false);
                $table->json('recipients')->nullable();             // extra e-mail addresses (besides the admins)
                $table->boolean('is_active')->default(true);
                $table->string('last_state', 10)->default('ok');    // ok | triggered (a new event is created only on ok -> triggered or a changed value)
                $table->timestamp('last_checked_at')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'is_active']);
            });
        }

        // B4: history of fired alerts
        if (!Schema::hasTable('fin_alert_events')) {
            Schema::create('fin_alert_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->foreign('rule_id')->references('id')->on('fin_alert_rules')->onDelete('set null');
                $table->string('severity', 10)->default('warning');
                $table->string('message', 500);
                $table->decimal('value', 18, 4)->nullable();
                $table->decimal('threshold', 18, 4)->nullable();
                $table->date('period_from')->nullable();
                $table->date('period_to')->nullable();
                $table->json('context')->nullable();
                $table->boolean('notified_app')->default(false);
                $table->boolean('notified_email')->default(false);
                $table->timestamp('acknowledged_at')->nullable();
                $table->unsignedInteger('acknowledged_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'created_at']);
                $table->index('rule_id');
            });
        }

        // B5: first-time setup wizard state, one row per company
        if (!Schema::hasTable('fin_onboarding')) {
            Schema::create('fin_onboarding', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
                $table->json('steps')->nullable();                  // {"company":"done","entities":"done","chart":"pending","import":"skipped","dashboard":"pending"}
                $table->string('chart_template', 20)->nullable();   // nss | micro
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();      // "do it later" hides the banner until the next visit of the wizard
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique('company_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('fin_onboarding');
        Schema::dropIfExists('fin_alert_events');
        Schema::dropIfExists('fin_alert_rules');
        Schema::dropIfExists('fin_fx_rates');
        Schema::dropIfExists('fin_counterparty_mappings');
        Schema::dropIfExists('fin_bank_statements');

        DB::table('fin_entries')->where('source', 'bank_statement')->update(['source' => 'manual']);
        DB::statement("ALTER TABLE fin_entries MODIFY source ENUM('manual','excel','google_sheets','csv','api','invoice','expense','demo') NOT NULL DEFAULT 'manual'");

        if (Schema::hasColumn('products', 'cost_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('cost_price');
            });
        }
    }

};
