<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users are identified by (tenant_id, azure_oid) only. The same person
     * may exist once per tenant (home account and guest accounts), all with
     * the same email, so the email keeps an index but is no longer unique.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->index('email');
        });
    }

    /**
     * Fails if several users already share an email; deduplicate first.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->unique('email');
        });
    }
};
