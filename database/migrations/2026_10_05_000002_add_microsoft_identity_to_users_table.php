<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users sign in with Microsoft: they are identified by their object ID
     * (`oid`) inside a tenant (`tid`) and no longer need a local password.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('azure_oid', 36)->nullable()->after('id');
            $table->string('tenant_id', 36)->nullable()->after('azure_oid');
            $table->foreignId('company_id')->nullable()->after('tenant_id')
                ->constrained('companies')->nullOnDelete();
            $table->string('job_title')->nullable()->after('email');
            $table->string('photo_path')->nullable()->after('job_title');
            $table->timestamp('last_login_at')->nullable()->after('photo_path');
            $table->string('password')->nullable()->change();

            // Several NULL pairs are allowed (users without a Microsoft
            // identity yet) on both SQLite and MySQL.
            $table->unique(['tenant_id', 'azure_oid']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'azure_oid']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['azure_oid', 'tenant_id', 'job_title', 'photo_path', 'last_login_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
