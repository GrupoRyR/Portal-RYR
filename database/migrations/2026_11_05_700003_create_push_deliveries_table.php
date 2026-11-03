<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per announcement and device that the cron sent a phone notice
     * to (odd/tasks/avisos.md T2), written before the push service is
     * called: the unique pair (announcement, endpoint hash) is what keeps a
     * device from getting it twice, even after its subscription is deleted
     * and the same endpoint comes back.
     */
    public function up(): void
    {
        Schema::create('push_deliveries', function (Blueprint $table) {
            $table->id();
            // Explicit names: every identifier stays under MariaDB's 64 characters.
            $table->foreignId('announcement_id')
                ->constrained(indexName: 'push_deliveries_announcement_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('push_subscription_id')
                ->nullable()
                ->constrained(indexName: 'push_deliveries_subscription_id_foreign')
                ->nullOnDelete();
            $table->char('endpoint_hash', 64);
            // pending (being sent, or a run that died), sent, failed or gone (404/410).
            $table->string('status', 10);
            $table->timestamp('created_at');

            $table->unique(['announcement_id', 'endpoint_hash'], 'push_deliveries_announcement_endpoint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
    }
};
