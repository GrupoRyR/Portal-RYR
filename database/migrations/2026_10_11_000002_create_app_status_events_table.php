<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * History of the statuses reported through the API (brief §6b, §8):
     * one row per change, never for a repeated identical report. The
     * integration is kept as a reference; its token is never stored here.
     */
    public function up(): void
    {
        Schema::create('app_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16);
            $table->string('message', 140)->nullable();
            $table->string('source', 100)->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['app_id', 'created_at']);
            $table->index(['integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_status_events');
    }
};
