<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a company's announcements may be emailed
     * (odd/tasks/inicio-fase-2.md WU4): its all-staff list and the mailbox
     * of its own tenant they go out from (an Exchange list only accepts
     * internal senders). Both or neither, at one of the company's domains
     * (App\Livewire\Admin\CompanyEditor).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // The list everybody of the company is in, e.g. todos@segurex.com.
            $table->string('all_staff_email', 254)->nullable()->after('domains');
            // The mailbox the email goes out from, e.g. segurex.info@segurex.com.
            $table->string('mail_sender', 254)->nullable()->after('all_staff_email');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['all_staff_email', 'mail_sender']);
        });
    }
};
