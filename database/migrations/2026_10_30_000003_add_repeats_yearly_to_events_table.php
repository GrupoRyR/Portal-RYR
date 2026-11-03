<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company dates that come back every year (odd/tasks/ajustes-inicio-y-tareas.md
     * T3): the anniversary, the accounting close, the uniform delivery. Only
     * an all-day event repeats (App\Models\Event::repeatsYearly()); its
     * stored days are the first occurrence.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('repeats_yearly')->default(false)->after('all_day');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('repeats_yearly');
        });
    }
};
