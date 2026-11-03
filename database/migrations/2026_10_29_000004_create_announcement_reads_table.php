<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mandatory reading (odd/tasks/inicio-fase-2.md WU1): an announcement
     * may ask everybody who sees it to confirm they read it, once per
     * account. Reads go away with the account or the announcement.
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->boolean('requires_read')->default(false)->after('is_pinned');
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['announcement_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('requires_read');
        });
    }
};
