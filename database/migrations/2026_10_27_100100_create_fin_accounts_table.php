<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AFV MVP: chart of accounts (simplified Bulgarian NSS plan)
return new class extends Migration {

    public function up()
    {
        Schema::create('fin_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->string('code', 20);
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->enum('subtype', [
                'current_asset', 'non_current_asset', 'cash', 'receivable', 'inventory',
                'current_liability', 'non_current_liability',
                'cogs', 'payroll', 'opex', 'depreciation', 'interest', 'tax', 'other',
            ])->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('fin_accounts');
    }

};
