<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Events by email to each company's all-staff list
     * (odd/tasks/observaciones.md O1), like announcements: `email_lists`
     * asks for it, and one row per event and company records the email
     * (App\Calendar\EventListEmails). The row is written before Microsoft
     * Graph is called and the pair is unique, so an event reaches each list
     * once, even if a run of the cron crashes. Explicit index names: every
     * identifier stays under MariaDB's 64 characters.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('email_lists')->default(false)->after('audience');
        });

        Schema::create('event_list_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')
                ->constrained(indexName: 'event_list_emails_event_fk')
                ->cascadeOnDelete();
            $table->foreignId('company_id')
                ->constrained(indexName: 'event_list_emails_company_fk')
                ->cascadeOnDelete();
            // pending, sending, sent or failed (App\Models\EventListEmail).
            $table->string('status', 10);
            $table->unsignedSmallInteger('attempts')->default(0);
            // A short code: Graph's error code, AADSTS…, «HTTP 502»…; never a message.
            $table->string('last_error', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            // «Reintentar»: at most once every few minutes per row.
            $table->timestamp('retried_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'company_id'], 'event_list_emails_event_company_unique');
            $table->index(['status', 'updated_at'], 'event_list_emails_status_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_list_emails');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('email_lists');
        });
    }
};
