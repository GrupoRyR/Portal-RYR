<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products of a company's catalog. A category is deleted together with
     * its products; the photo files are removed by the admin page.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', indexName: 'products_company_fk')->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained('product_categories', indexName: 'products_category_fk')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('summary', 280)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'published'], 'products_company_pub_ix');
            $table->index(['product_category_id', 'position'], 'products_category_pos_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
