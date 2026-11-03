<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Grupo de Gerencia» (odd/tasks/panel-gerencial.md): the object ID of
     * the security group of the company's own tenant whose members get the
     * `management` role at sign-in (App\Auth\ManagementGroup). Null: nobody
     * has the role in that company.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('management_group_id', 36)->nullable()->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('management_group_id');
        });
    }
};
