<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasks and approvals (docs/contrato-tareas-aprobaciones.md §2.2): the
     * catalog app whose host is the only one allowed in the «Ver» links of
     * the work items, and the base URL the portal calls to complete a task.
     * Both are required with the `work:write` ability.
     */
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->foreignId('app_id')->nullable()->after('allowed_ips')->constrained('apps')->nullOnDelete();
            $table->string('app_base_url', 255)->nullable()->after('app_id');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('app_id');
            $table->dropColumn('app_base_url');
        });
    }
};
