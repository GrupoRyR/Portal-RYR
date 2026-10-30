<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The people of each company, copied every night from Microsoft Graph
     * by `portal:sync-directory` (App\Directory\DirectorySync,
     * odd/tasks/personas.md WU1). Not `people`: that table links the
     * accounts one person has in several companies.
     *
     * One row per Entra user (`tenant_id` + `azure_oid`, lowercase GUIDs),
     * in the company the sign-in rule gives them. `search_text` is the
     * row's text through App\Catalog\AppSearch::normalize(), so «perez»
     * finds «Pérez» on any database. The personal mobile phone is never
     * copied.
     */
    public function up(): void
    {
        Schema::create('directory_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_id', 36);
            $table->string('azure_oid', 36);
            $table->string('display_name');
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('office_location')->nullable();
            $table->string('business_phone', 64)->nullable();
            $table->string('email', 320);
            $table->date('hire_date')->nullable();
            $table->text('search_text');
            $table->dateTime('synced_at');

            $table->unique(['tenant_id', 'azure_oid']);
            $table->index(['company_id', 'display_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_entries');
    }
};
