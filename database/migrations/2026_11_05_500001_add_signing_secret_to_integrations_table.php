<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Completar» (docs/contrato-tareas-aprobaciones.md §6.2): the secret a
     * `work:write` integration's calls are signed with. The portal needs it
     * in clear to sign, so it is stored encrypted with APP_KEY (cast
     * `encrypted`, about 250 characters), never hashed; `signing_secret_hint`
     * keeps its last four characters to recognise it in the list. No index.
     */
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->text('signing_secret')->nullable()->after('app_base_url');
            $table->string('signing_secret_hint', 4)->nullable()->after('signing_secret');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn(['signing_secret', 'signing_secret_hint']);
        });
    }
};
