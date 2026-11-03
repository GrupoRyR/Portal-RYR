<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Monday digest by email (odd/tasks/inicio-fase-2.md WU3):
     * `weekly_digest_enabled` turns it on per company (Admin › Empresas),
     * and one row per company and week records it (App\Digest\WeeklyDigests).
     * The row is written before Microsoft Graph is called and the pair is
     * unique, so a list never gets the same week twice, even if a run of
     * the cron crashes.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('weekly_digest_enabled')->default(false)->after('mail_sender');
        });

        Schema::create('weekly_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // The Monday of the week, in Bogotá.
            $table->date('week_start');
            // sending, sent, failed or empty (App\Models\WeeklyDigest).
            $table->string('status', 10);
            // A short code: Graph's error code, AADSTS…, «HTTP 502»…; never a message.
            $table->string('error_code', 64)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'week_start']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_digests');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('weekly_digest_enabled');
        });
    }
};
