<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Todas las empresas activas» (brief §5.2): the app counts as linked to
     * every active company, including companies added later, without rows
     * in `app_companies`. Access rules still decide who sees it.
     */
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->boolean('available_to_all_companies')->default(false)->after('app_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('available_to_all_companies');
        });
    }
};
