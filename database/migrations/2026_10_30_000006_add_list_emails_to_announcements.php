<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Announcements by email to each company's all-staff list
     * (odd/tasks/inicio-fase-2.md WU4): `email_lists` asks for it, and one
     * row per announcement and company records the email
     * (App\Announcements\AnnouncementListEmails). The row is written before
     * Microsoft Graph is called and the pair is unique, so an announcement
     * reaches each list once, even if a run of the cron crashes.
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->boolean('email_lists')->default(false)->after('requires_read');
        });

        Schema::create('announcement_list_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // pending, sending, sent or failed (App\Models\AnnouncementListEmail).
            $table->string('status', 10);
            $table->unsignedSmallInteger('attempts')->default(0);
            // A short code: Graph's error code, AADSTS…, «HTTP 502»…; never a message.
            $table->string('last_error', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            // «Reintentar»: at most once every few minutes per row.
            $table->timestamp('retried_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'company_id']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_list_emails');

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('email_lists');
        });
    }
};
