<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name');
            $table->string('slug')->unique();
            // Not unique: two companies may share one Microsoft tenant and
            // are then told apart by their email domains.
            $table->string('tenant_id', 36)->index();
            $table->boolean('is_primary_for_tenant')->default(true);
            $table->json('domains');
            $table->string('logo_light_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('icon_path')->nullable();
            $table->string('color_primary', 7);
            $table->string('color_accent', 7);
            $table->string('color_dark', 7);
            $table->json('quick_links')->nullable();
            // Inactive companies are kept: the app never deletes them.
            $table->boolean('is_active')->default(true);
            $table->timestamp('consent_verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
