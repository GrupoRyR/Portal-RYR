<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The application catalog: categories, apps and the companies each app
     * applies to. Allowed values of the string columns are listed as
     * constants on App\Models\App.
     */
    public function up(): void
    {
        Schema::create('app_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            // Fixed identifier used by the API and Soporte TI; never changes.
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description');
            $table->string('icon_path')->nullable();
            $table->string('icon_dark_path')->nullable();
            $table->string('accent_color', 7);
            $table->string('url', 2048)->nullable();
            $table->string('type', 16);
            $table->string('open_in', 16);
            $table->string('hosting', 16);
            $table->string('login_type', 16);
            $table->foreignId('app_category_id')->constrained('app_categories')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active')->index();
            $table->text('maintenance_message')->nullable();
            $table->dateTime('maintenance_until')->nullable();
            $table->text('local_instructions')->nullable();
            $table->string('download_url', 2048)->nullable();
            // Reported by external systems (Phase 8); only read until then.
            $table->string('external_status', 16)->nullable();
            $table->string('external_status_message', 140)->nullable();
            $table->dateTime('external_status_at')->nullable();
            $table->timestamps();
        });

        // An app with no row here is shown to no company.
        Schema::create('app_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['app_id', 'company_id']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_companies');
        Schema::dropIfExists('apps');
        Schema::dropIfExists('app_categories');
    }
};
