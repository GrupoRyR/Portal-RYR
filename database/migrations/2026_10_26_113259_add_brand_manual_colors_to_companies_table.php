<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colors the official brand manual (SGX/SDC, 2026-10-23) fixes per
     * company on top of primary/accent/dark. Null means "derive it from the
     * primary" (App\Branding\BrandPalette), as for any company added later.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('color_button', 7)->nullable()->after('color_dark');
            $table->string('color_ink', 7)->nullable()->after('color_button');
            $table->string('color_text', 7)->nullable()->after('color_ink');
            $table->string('color_ink_dark_mode', 7)->nullable()->after('color_text');
            $table->string('color_text_dark_mode', 7)->nullable()->after('color_ink_dark_mode');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['color_button', 'color_ink', 'color_text', 'color_ink_dark_mode', 'color_text_dark_mode']);
        });
    }
};
