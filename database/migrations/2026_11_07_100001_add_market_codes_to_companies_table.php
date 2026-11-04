<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The input series a company sees in its markets band
     * (odd/tasks/mercados-o5.md M3): a JSON list of input codes of
     * App\Markets\MarketSeries, e.g. ["zinc","copper"]. Null: only the
     * series for everyone. Chosen in Admin, never derived from the slug.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('market_codes')->nullable()->after('quick_links');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('market_codes');
        });
    }
};
