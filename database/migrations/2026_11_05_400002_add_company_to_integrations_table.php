<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Panel gerencial (odd/tasks/panel-gerencial.md): the only company whose
     * figures a `kpis:write` integration may replace. Required with that
     * ability (App\Livewire\Admin\IntegrationManager).
     */
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('app_base_url');
            $table->foreign('company_id', 'integrations_company_fk')->references('id')->on('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropForeign('integrations_company_fk');
            $table->dropColumn('company_id');
        });
    }
};
