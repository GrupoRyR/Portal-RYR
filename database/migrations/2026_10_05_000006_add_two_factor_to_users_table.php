<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local emergency account (password + TOTP) for when Microsoft sign-in
     * is unavailable. Microsoft users never use these columns.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_emergency')->default(false)->after('company_id');
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            // Last accepted TOTP time step; a code from this step or an
            // older one is a replay.
            $table->unsignedBigInteger('two_factor_last_timestep')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_emergency', 'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_timestep']);
        });
    }
};
