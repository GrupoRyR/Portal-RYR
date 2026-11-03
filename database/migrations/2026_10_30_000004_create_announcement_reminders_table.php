<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reminders of a mandatory announcement (odd/tasks/ajustes-inicio-y-tareas.md
     * T5): «Enviar recordatorio» records a request; the cron
     * (portal:send-announcement-reminders) mails the people who are missing
     * in batches and records one delivery per person, so nobody gets two
     * emails for the same reminder.
     */
    public function up(): void
    {
        // The first production run (2026-11-03) failed on MySQL's 64-character
        // identifier limit after creating both tables, and MySQL does not roll
        // back DDL. Nothing could use them while this migration was pending,
        // so they are dropped and built again.
        Schema::dropIfExists('announcement_reminder_deliveries');
        Schema::dropIfExists('announcement_reminders');

        Schema::create('announcement_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            // People missing when it was requested (the audit entry has it too).
            $table->unsignedInteger('missing_count');
            // Null while the cron is still sending it.
            $table->timestamp('finished_at')->nullable();
            // Set too when it ended because the announcement stopped asking
            // for confirmation (withdrawn, expired or no longer mandatory).
            $table->timestamp('stopped_at')->nullable();

            $table->index(['announcement_id', 'requested_at']);
            $table->index('finished_at');
        });

        Schema::create('announcement_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            // Explicit names: the generated ones pass MySQL's 64-character limit.
            $table->foreignId('announcement_reminder_id')
                ->constrained(indexName: 'reminder_deliveries_reminder_id_foreign')
                ->cascadeOnDelete();
            // App\Auth\LinkedAccounts::PERSON_KEY of the person mailed.
            $table->unsignedBigInteger('person_key');
            // The account the email went to.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10);
            $table->timestamp('created_at');

            $table->unique(['announcement_reminder_id', 'person_key'], 'reminder_deliveries_reminder_person_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reminder_deliveries');
        Schema::dropIfExists('announcement_reminders');
    }
};
