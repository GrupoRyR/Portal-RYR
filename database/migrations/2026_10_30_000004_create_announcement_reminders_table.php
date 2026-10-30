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
        Schema::create('announcement_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            // People missing when it was requested (the audit entry has it too).
            $table->unsignedInteger('missing_count');
            // Null while the cron is still sending it.
            $table->timestamp('finished_at')->nullable();

            $table->index(['announcement_id', 'requested_at']);
            $table->index('finished_at');
        });

        Schema::create('announcement_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_reminder_id')->constrained()->cascadeOnDelete();
            // App\Auth\LinkedAccounts::PERSON_KEY of the person mailed.
            $table->unsignedBigInteger('person_key');
            // The account the email went to.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10);
            $table->timestamp('created_at');

            $table->unique(['announcement_reminder_id', 'person_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reminder_deliveries');
        Schema::dropIfExists('announcement_reminders');
    }
};
