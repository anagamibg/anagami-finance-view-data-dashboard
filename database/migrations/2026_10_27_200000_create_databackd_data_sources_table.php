<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AFV MVP (S2): saved external data sources (Google Sheets, CSV URL) that can be re-synchronised with a stored mapping
return new class extends Migration {

    public function up()
    {
        Schema::create('fin_data_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->enum('type', ['google_sheets', 'csv_url', 'excel'])->default('google_sheets');
            $table->string('name');
            $table->text('url')->nullable();
            $table->string('sheet', 100)->nullable();
            $table->json('mapping')->nullable();
            $table->unsignedBigInteger('default_entity_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedBigInteger('last_batch_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fin_data_sources');
    }

};
