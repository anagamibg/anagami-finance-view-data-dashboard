<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AFV MVP: log of imports of financial data (Excel, Google Sheets, CSV, API)
return new class extends Migration {

    public function up()
    {
        Schema::create('fin_import_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->string('source', 30);
            $table->string('file_name')->nullable();
            $table->text('source_url')->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->string('status', 30)->default('pending');
            $table->json('mapping')->nullable();
            $table->json('errors')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('fin_import_batches');
    }

};
