<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-wide settings of Configuración (App\Settings\PortalSettings):
     * one row per saved key, the value JSON-encoded. A key without a row
     * falls back to config/portal.php, so new settings need no migration.
     */
    public function up(): void
    {
        Schema::create('portal_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_settings');
    }
};
