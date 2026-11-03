<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The management figures of each company (odd/tasks/panel-gerencial.md):
     * one row per figure of the company's current set, at most 8. The set's
     * own fields (`period_label`, `as_of`, `source_label`) repeat on every
     * row, so a set is replaced by deleting and inserting the company's rows
     * in one transaction and read back with one query. No history is kept.
     *
     * Index and foreign key names are explicit and short: MySQL allows 64
     * characters.
     */
    public function up(): void
    {
        Schema::create('company_kpis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            // The integration that published the set; revoking it deletes them.
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->unsignedTinyInteger('position');
            $table->string('key', 40);
            $table->string('label', 60);
            $table->decimal('value', 20, 4);
            $table->string('unit', 16)->nullable();
            $table->decimal('goal', 20, 4)->nullable();
            $table->string('note', 120)->nullable();
            $table->string('period_label', 40);
            $table->timestamp('as_of');
            $table->string('source_label', 80);
            $table->timestamps();

            $table->foreign('company_id', 'company_kpis_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('integration_id', 'company_kpis_integration_fk')->references('id')->on('integrations')->nullOnDelete();
            $table->unique(['company_id', 'key'], 'company_kpis_company_key_unique');
            $table->index(['company_id', 'position'], 'company_kpis_company_position_index');
            $table->index('integration_id', 'company_kpis_integration_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_kpis');
    }
};
