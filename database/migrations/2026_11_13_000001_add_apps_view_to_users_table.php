<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The view of «Mis aplicaciones» each account chose on the home screen
     * (App\View\Home\AppsView): null until it chooses, which reads as
     * «Categorías», the home as it always was. Per account, like the
     * favorites.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apps_view', 16)->nullable()->after('show_birthday');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('apps_view');
        });
    }
};
