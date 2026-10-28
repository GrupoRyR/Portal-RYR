<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `login_hint` to send Microsoft for this account (re-authentication,
     * later the company switch): the id_token `login_hint` claim, else the
     * sign-in name, else the email. Refreshed on every sign-in; null until
     * the next one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('microsoft_login_hint', 255)->nullable()->after('azure_oid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('microsoft_login_hint');
        });
    }
};
