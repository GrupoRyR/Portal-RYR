<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the group is a security group (securityEnabled, not a
     * Microsoft 365 "Unified" group). Only `true` grants apps. Groups
     * stored before this column are null (unknown, grants nothing) until
     * the user's next sign-in refreshes them.
     */
    public function up(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            $table->boolean('security_group')->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            $table->dropColumn('security_group');
        });
    }
};
