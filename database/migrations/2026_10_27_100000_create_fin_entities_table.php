<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AFV MVP: legal entities / subsidiaries inside a company (used for consolidation)
return new class extends Migration {

    public function up()
    {
        Schema::create('fin_entities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->string('name');
            $table->string('eik', 20)->nullable();
            $table->string('country', 2)->default('BG');
            $table->string('currency', 3)->default('EUR');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->decimal('ownership_pct', 5, 2)->default(100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'parent_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('fin_entities');
    }

};
