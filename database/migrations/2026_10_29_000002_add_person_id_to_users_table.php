<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The person an account is linked to (App\Auth\LinkedAccounts), at most
     * one account per company. Several NULL pairs are allowed (accounts
     * that are not linked) on both SQLite and MySQL.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->after('company_id')
                ->constrained('people')->nullOnDelete();

            $table->unique(['person_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropUnique(['person_id', 'company_id']);
            $table->dropColumn('person_id');
        });
    }
};
