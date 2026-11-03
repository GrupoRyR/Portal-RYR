<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Services of a company that IT reports by hand in Administración ›
     * Estado de TI (correo, internet, VPN...). The apps report their own
     * status (apps.status and apps.external_status*). Index and key names
     * are explicit: MariaDB allows 64 characters at most.
     */
    public function up(): void
    {
        Schema::create('it_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', indexName: 'it_svc_company_fk')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('status', 16)->default('operational');
            $table->string('message', 160)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'it_svc_company_name_uq');
            $table->index(['company_id', 'position'], 'it_svc_company_pos_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_services');
    }
};
