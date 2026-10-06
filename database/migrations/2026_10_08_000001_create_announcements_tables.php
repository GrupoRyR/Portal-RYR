<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Announcements on the home screen (brief §4 «Anuncios»): for every
     * company (`audience` = all) or for the companies in
     * `announcement_company`, shown between `starts_at` and `ends_at`
     * (null = no end) unless withdrawn.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('body', 280);
            $table->string('image_path')->nullable();
            $table->string('audience', 16)->default('all');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->dateTime('withdrawn_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['withdrawn_at', 'starts_at', 'ends_at']);
        });

        Schema::create('announcement_company', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->primary(['announcement_id', 'company_id']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_company');
        Schema::dropIfExists('announcements');
    }
};
