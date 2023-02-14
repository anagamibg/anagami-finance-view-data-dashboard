<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AFV MVP: universal financial record (flow = turnover for the period, balance = account balance at the date)
return new class extends Migration {

    public function up()
    {
        Schema::create('fin_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('account_id');
            $table->date('entry_date');
            $table->enum('kind', ['flow', 'balance'])->default('flow');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->decimal('amount_base', 15, 2);
            $table->unsignedBigInteger('region_id')->nullable();
            $table->unsignedBigInteger('client_segment_id')->nullable();
            $table->unsignedBigInteger('product_group_id')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->unsignedInteger('client_id')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->string('document_no', 60)->nullable();
            $table->string('description')->nullable();
            $table->enum('source', ['manual', 'excel', 'google_sheets', 'csv', 'api', 'invoice', 'expense', 'demo'])->default('manual');
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'entry_date']);
            $table->index(['company_id', 'account_id', 'entry_date']);
            $table->index(['company_id', 'entity_id']);
            $table->index('import_batch_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fin_entries');
    }

};
