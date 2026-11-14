<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The account's own sign-in name (UPN), in lower case: what the agent
     * matches the panel token's `sub` against (docs/agente-conexion.md).
     * Refreshed on every Microsoft sign-in by App\Auth\MicrosoftLogin, so it
     * is null until the account signs in again. A guest UPN (`#EXT#`) is
     * never kept, and emergency accounts have none. `email` is not changed:
     * it stays `mail` first, the UPN only when `mail` is missing.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('upn', 255)->nullable()->after('email');
            $table->index('upn', 'users_upn_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_upn_index');
            $table->dropColumn('upn');
        });
    }
};
