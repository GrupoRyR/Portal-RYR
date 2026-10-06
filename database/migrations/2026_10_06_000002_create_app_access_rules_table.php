<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who gets each app (App\Models\AppAccessRule): a Microsoft group of a
     * company, one user, or everyone in a company.
     */
    public function up(): void
    {
        Schema::create('app_access_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            // Group display name, user id, or null for "the whole company".
            $table->string('value')->nullable();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // NULLs are distinct in unique indexes, so duplicate company
            // rules are also prevented by AppAccessRule::forCompany().
            $table->unique(['app_id', 'type', 'value', 'company_id']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_access_rules');
    }
};
