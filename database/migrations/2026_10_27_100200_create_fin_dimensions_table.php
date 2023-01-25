<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AFV MVP: analysis dimensions (region, client segment, product group, cost center)
return new class extends Migration {

    public function up()
    {
        Schema::create('fin_dimensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->enum('type', ['region', 'client_segment', 'product_group', 'cost_center']);
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('fin_dimensions');
    }

};
