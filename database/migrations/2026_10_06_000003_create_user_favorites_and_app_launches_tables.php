<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'app_id']);
        });

        // One row per app opened (brief §5.2: who, which app, when, from
        // which kind of device). No IP address and no raw User-Agent are
        // kept, to store as little personal data as possible.
        Schema::create('app_launches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device', 16);
            $table->dateTime('launched_at');

            $table->index(['user_id', 'launched_at']);
            $table->index(['app_id', 'launched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_launches');
        Schema::dropIfExists('user_favorites');
    }
};
