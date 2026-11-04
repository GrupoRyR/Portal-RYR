<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prices an admin types by hand in Administración › Mercados
     * (odd/tasks/mercados-o5.md M4): supplier quotes of inputs no free
     * source publishes (soda cáustica, PET...). The home band of the company
     * shows the latest price of each name (App\Markets\MarketBoard). Index
     * and key names are explicit: MariaDB allows 64 characters at most.
     */
    public function up(): void
    {
        Schema::create('market_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', indexName: 'mkt_inputs_company_fk')->cascadeOnDelete();
            $table->string('label', 40);
            // A manual unit of App\Markets\MarketUnit (`cop_kg`, `usd_tonne`...).
            $table->string('unit', 12);
            $table->decimal('value', 14, 4);
            $table->date('valid_on');
            $table->string('source', 80)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'mkt_inputs_creator_fk')->nullOnDelete();
            $table->timestamps();

            // The band and the admin list read one company by date.
            $table->index(['company_id', 'valid_on'], 'mkt_inputs_company_on_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_inputs');
    }
};
