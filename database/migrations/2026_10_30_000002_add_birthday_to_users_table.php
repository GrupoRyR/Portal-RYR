<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The birthday each person writes on /cuenta («Mi cumpleaños»): only
     * the day and the month, never the year, and whether the people of the
     * account's company see it in «Mi día» (off until the person turns it
     * on; Ley 1581). Per account, like everything else.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('birthday_month')->nullable()->after('job_title');
            $table->unsignedTinyInteger('birthday_day')->nullable()->after('birthday_month');
            $table->boolean('show_birthday')->default(false)->after('birthday_day');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birthday_month', 'birthday_day', 'show_birthday']);
        });
    }
};
