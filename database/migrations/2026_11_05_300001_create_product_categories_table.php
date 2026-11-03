<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product categories of a company's catalog (Administración › Catálogos).
     * Index and key names are explicit: MySQL allows 64 characters at most.
     */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', indexName: 'prod_cat_company_fk')->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'prod_cat_company_name_uq');
            $table->index(['company_id', 'position'], 'prod_cat_company_pos_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
