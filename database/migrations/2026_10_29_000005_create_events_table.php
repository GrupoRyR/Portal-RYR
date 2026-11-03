<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company events (odd/tasks/inicio-fase-2.md WU2): for every company
     * (`audience` = all) or for the companies in `event_company`, shown
     * from `published_at` until they are over unless withdrawn, like
     * announcements. Datetimes are in the app timezone (Bogotá); an
     * all-day event keeps midnight of its first day in `starts_at` and of
     * its last day in `ends_at` (null = one day).
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->string('location', 120)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('audience', 16)->default('all');
            $table->dateTime('published_at');
            $table->dateTime('withdrawn_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['withdrawn_at', 'published_at', 'starts_at']);
        });

        Schema::create('event_company', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->primary(['event_id', 'company_id']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_company');
        Schema::dropIfExists('events');
    }
};
