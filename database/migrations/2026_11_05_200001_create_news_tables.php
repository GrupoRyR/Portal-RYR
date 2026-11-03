<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Noticias (odd/tasks/noticias.md): the RSS/Atom sources of each company
     * and tab, and their latest headlines. Only the title, the https link
     * and the date of an item are stored, never a description or HTML.
     * Index and key names are explicit and short (MySQL allows 64).
     */
    public function up(): void
    {
        Schema::create('news_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained(indexName: 'news_sources_company_fk')->cascadeOnDelete();
            // colombia, internacional or sector (App\Models\NewsSource::TABS).
            $table->string('tab', 20);
            $table->string('name', 80);
            $table->string('url', 2048);
            $table->boolean('active')->default(true);
            $table->timestamp('last_fetched_at')->nullable();
            // A short code (App\News\FeedFailure), never a message or a body.
            $table->string('last_error_code', 32)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'tab', 'active'], 'news_sources_company_tab_idx');
            $table->index('active', 'news_sources_active_idx');
        });

        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_source_id')->constrained(indexName: 'news_items_source_fk')->cascadeOnDelete();
            $table->string('title', 300);
            $table->string('link', 2048);
            $table->timestamp('published_at');
            // sha256 of the guid, the Atom id or else the link.
            $table->char('guid_hash', 64);
            $table->timestamps();

            $table->unique(['news_source_id', 'guid_hash'], 'news_items_source_guid_uq');
            $table->index(['news_source_id', 'published_at'], 'news_items_source_date_idx');
            $table->index('published_at', 'news_items_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_items');
        Schema::dropIfExists('news_sources');
    }
};
