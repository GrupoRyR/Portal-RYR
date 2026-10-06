<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría reads both sources newest first, 50 rows at a time, with a
     * (time, id) cursor (App\Audit\AuditFeed): these indexes let each source
     * stop after the page instead of sorting the whole table.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['created_at', 'id'], 'activity_log_created_at_id_index');
        });

        Schema::table('app_launches', function (Blueprint $table) {
            $table->index(['launched_at', 'id'], 'app_launches_launched_at_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('app_launches', function (Blueprint $table) {
            $table->dropIndex('app_launches_launched_at_id_index');
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_created_at_id_index');
        });
    }
};
