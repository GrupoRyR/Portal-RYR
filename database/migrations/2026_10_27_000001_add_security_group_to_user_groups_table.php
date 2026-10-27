<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the group is a security group (securityEnabled, not a
     * Microsoft 365 "Unified" group). Only those grant apps. Stored groups
     * start as false and are refreshed at the user's next sign-in.
     */
    public function up(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            $table->boolean('security_group')->default(false)->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            $table->dropColumn('security_group');
        });
    }
};
