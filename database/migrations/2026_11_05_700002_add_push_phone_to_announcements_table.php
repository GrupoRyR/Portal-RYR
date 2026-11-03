<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Enviar al celular» (odd/tasks/avisos.md T2): the publisher asks for a
     * phone notice; the cron (portal:send-announcement-pushes) sends it and
     * sets `push_finished_at` once every device of its audience got it.
     * Ticking the box again clears it (App\Models\Announcement::booted()).
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->boolean('push_phone')->default(false);
            $table->timestamp('push_finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['push_phone', 'push_finished_at']);
        });
    }
};
